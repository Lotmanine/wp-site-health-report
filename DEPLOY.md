# Deploying on Coolify

The stack runs as one Docker Compose resource on the VPS. Only the `wordpress` service gets a domain.
The DNS record already exists: `health.oldev.site`, A, `76.13.63.48`, DNS-only.

## 1. Create the resource

1. In Coolify, add a new resource: **Docker Compose**, from the public repository
   `Lotmanine/wp-site-health-report`, branch `main`, compose file `docker-compose.yml`.
2. On the `wordpress` service set the domain to `https://health.oldev.site`, port `80`.
3. Leave `db`, `scanner` and `scanner-worker` without a domain.

The scanner is reached through the WordPress Apache at `https://health.oldev.site/scanner/api/`. Only
`/scanner/api/` is proxied and every route under it needs the API key, so `/scanner/up` returns 404 and
no second hostname is needed.

## 2. Set environment variables

Generate each secret with `openssl rand -hex 32`. Generate `SCANNER_APP_KEY` with
`echo "base64:$(openssl rand -base64 32)"`.

| Variable | Value |
|---|---|
| `WP_HOME` | `https://health.oldev.site` |
| `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`, `WP_ADMIN_EMAIL` | First admin account |
| `DB_PASSWORD` | Random secret |
| `SHR_HMAC_SECRET` | Random secret, also used in n8n |
| `SHR_APP_SECRET` | Random secret, different from the one above |
| `SHR_N8N_WEBHOOK_URL` | `https://n8n.oldev.site/webhook/shr-scan` |
| `SHR_CALLBACK_BASE` | Leave empty |
| `SHR_SCANNER_URL` | `https://health.oldev.site/scanner` |
| `SCANNER_APP_KEY` | `base64:` key |
| `SCANNER_API_KEY` | Random secret, also used in n8n |
| `SHR_TURNSTILE_SITE_KEY`, `SHR_TURNSTILE_SECRET_KEY` | From the Turnstile widget in step 3 |
| `SHR_TRUSTED_PROXY_HOPS` | `1` for Traefik alone. Use `2` if the record is ever proxied by Cloudflare. |
| `SHR_N8N_LEADS_URL` | `https://n8n.oldev.site/webhook/health-lead`. While empty, mail is written to `/tmp/shr-mail` in the container and lead events stay queued. |
| `ANTHROPIC_API_KEY`, `SHR_AI_MODEL` | Optional. With either empty, summaries are rule-based. |

The limits in `.env.example` have working defaults. `SHR_DAILY_EMAIL_CAP` can only lower the cap: the code
never allows more than 50 per day, because Brevo's 300-per-day allowance is shared with the main site.

## 3. Create the Turnstile widget

In Cloudflare, under Turnstile, add a widget for `health.oldev.site` in managed mode and copy both keys
into the variables above. `.env.example` holds Cloudflare's public test keys, which accept every token and
must not be used in production.

## 4. Connect n8n

The workflows already exist on `n8n.oldev.site`:

| Workflow | ID | State |
|---|---|---|
| Health Report - Scan Pipeline | `hU9lp0gKvTwJh5Ls` | Published |
| Health Report - Error Alert | `dzvAY3XW76YWTV15` | Published, runs only when the pipeline fails |
| Deliverability audit lead workflow | `h3jpKzBdDOGM1k9c` | Published, holds the `health-lead` branch below |

Credentials cannot be created through the n8n MCP tools. They exist on the instance:

| Credential | Type | Fields | Attached to |
|---|---|---|---|
| Health Report HMAC | Crypto | Hmac Secret: `SHR_HMAC_SECRET` | Compute Job Signature, Sign Result, Health: Compute Signature |
| Health Report Scanner Key | Header Auth | Name `X-Api-Key`, Value: `SCANNER_API_KEY` | Create Scanner Job, Get Scanner Result |

If `SHR_HMAC_SECRET` or `SCANNER_API_KEY` changes, update these two credentials too. Every scan failed with
"The scan could not finish" while the pipeline was unpublished: WordPress got a 404 from the webhook three
times and marked the scan `dispatch_failed`.

PageSpeed Insights runs without a key on the live pipeline. Keyless calls answered 429 during testing, so the
performance category is often left out of the score. To add a key, create a Query Auth credential (name `key`,
value a Google PageSpeed API key), set the PageSpeed Insights node to Generic Credential Type, Query Auth, and
attach it. `n8n/src` already builds the node that way.

### Lead and mail path

WordPress posts three message types to `SHR_N8N_LEADS_URL`, signed like the scan job (HMAC-SHA256 of the raw
body in `X-SHR-Signature`, `issued_at` within 300 seconds, a one-time nonce):

| Type | What n8n does |
|---|---|
| `lead.upsert` | Appends or updates the row for that address in the Health Check tab of the leads spreadsheet |
| `lead.erase` | Deletes every row for that address. Sent by delete-my-data and by the 30-day retention job |
| `mail.send` | Sends the report PDF or a sign-in link from lotfi@oldev.site through the Brevo SMTP credential, with no n8n attribution and the line "This message is sent automatically by Oldev." |

The branch is a separate webhook (`health-lead`) inside the deliverability audit workflow and is not connected
to the audit path. It answers 401 to a bad, stale or replayed signature. WordPress caps mail at 50 a day before
sending, and the n8n branch refuses a 51st `mail.send` on the same UTC day as a second guard.

In WordPress, lead events go to the `shr_outbox` table and Action Scheduler delivers them by row id, with
backoff for up to six attempts. The daily retention job requeues anything that ran out of attempts, so an
erasure is retried until n8n confirms it.

To rebuild the workflow JSON from `n8n/src`, run `sh scripts/build-workflows.sh`. The exported alert
workflow has a placeholder chat ID. The copy on the instance has the real one.

## 5. Wordfence

Every boot installs Wordfence 9.0.2 (`WORDFENCE_VERSION` overrides it) and applies
`docker/wordpress/wordfence-config.php`. The firewall runs in extended protection: Apache sets
`auto_prepend_file` to a loader outside the web root, so it runs before WordPress on every PHP request.
Visitor addresses come from Apache's mod_remoteip: at container start `docker/wordpress/shr-remoteip.php`
trusts `X-Forwarded-For` only from the networks the container is attached to, where the Coolify proxy
connects from, instead of every private range. WordPress and Wordfence then read `REMOTE_ADDR`, and no address
is allowlisted in the firewall. The n8n callbacks are checked by their HMAC signature. Brute force limits, rate limits and blocking of `admin`-style usernames are set there too, and
settings changed in wp-admin for those keys are reset on the next boot.

Two steps are left to the site owner in wp-admin:

1. **License.** Wordfence shows "Wordfence installation is incomplete" until a free license is registered under
   Wordfence, Resume Installation. Without it the firewall has no rules file, so attack patterns are not blocked
   yet. Brute force protection, rate limiting and two-factor login work without it.
2. **Two-factor login.** Under Wordfence, Login Security, enrol the admin account with an authenticator app and
   save the recovery codes. It is available to administrators and not forced on any role.

## 6. Check the deployment

1. `https://health.oldev.site/` loads and the form shows the Turnstile widget.
2. Run a scan of `https://oldev.site`. The report appears in about a minute.
3. In wp-admin, open **Health Report**: the queue has no failed actions and usage shows one scan.
4. A request to `https://health.oldev.site/scanner/api/v1/scans/x` without a key returns 401.

## Memory

The VPS has 8 GB with about 5 GB in use, so about 3 GB is free, and it may also host the booking project.
Measured with `docker stats` on the local stack, on PHP 8.5.12 and MariaDB 11.8.9:

| Service | Limit | Idle | Peak during a scan |
|---|---|---|---|
| `wordpress`, with the queue worker | 512 MiB | 57 MiB | 122 MiB |
| `db` | 256 MiB | 104 MiB | 104 MiB |
| `scanner` | 160 MiB | 38 MiB | 38 MiB |
| `scanner-worker` | 128 MiB | 35 MiB | 35 MiB |
| **Total** | **1056 MiB** | **234 MiB** | **299 MiB** |

The worst case is the sum of the limits, 1056 MiB. n8n already runs on the VPS and is not counted. The local
n8n container used about 626 MiB during a scan, and a scan adds to whatever the shared n8n already uses.

## Updating

Redeploy in Coolify. Each boot copies the plugin and theme from the image into WordPress and runs activation,
so schema changes apply by themselves. Data lives in the `db_data`, `wp_data` and `scanner_data` volumes.
