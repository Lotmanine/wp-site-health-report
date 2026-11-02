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
| `BREVO_API_KEY` | Transactional key. While empty, mail is written to `/tmp/shr-mail` in the container and not sent. |
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
| Health Report - Scan Pipeline | `hU9lp0gKvTwJh5Ls` | Created, not published |
| Health Report - Error Alert | `dzvAY3XW76YWTV15` | Published, runs only when the pipeline fails |

Credentials cannot be created through the n8n MCP tools, so add these by hand:

| Credential | Type | Fields | Attach to nodes |
|---|---|---|---|
| Health Report HMAC | Crypto | Hmac Secret: `SHR_HMAC_SECRET` | Compute Job Signature, Sign Result |
| Health Report Scanner Key | Header Auth | Name `X-Api-Key`, Value: `SCANNER_API_KEY` | Create Scanner Job, Get Scanner Result |
| Health Report PageSpeed Key | Query Auth | Name `key`, Value: a Google PageSpeed API key | PageSpeed Insights |

Without a Google key the PageSpeed API answered 429 on every keyless call during testing, so the
performance category is left out of the score until a key is added.

Then publish the pipeline.

To rebuild the workflow JSON from `n8n/src`, run `sh scripts/build-workflows.sh`. The exported alert
workflow has a placeholder chat ID. The copy on the instance has the real one.

## 5. Check the deployment

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
