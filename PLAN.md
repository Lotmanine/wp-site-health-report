# Website Health Report: plan

A visitor enters a website URL and receives a scored health report. The project is a public, working
proof of WordPress and integration engineering. It only ever makes passive, read-only requests.

## 1. Architecture

```
Browser
  | HTTPS, Turnstile token, consent box
  v
WordPress (block theme + oldev-health-report plugin)      health.oldev.site
  | Action Scheduler job
  | HMAC signed scan.job
  v
n8n workflow "Health Report - Scan Pipeline"              n8n.oldev.site
  |-- X-Api-Key --> Laravel scanner (queued passive checks)  health.oldev.site/scanner
  |-- PageSpeed Insights API
  |-- DNS-over-HTTPS (SPF, DKIM, DMARC)
  | HMAC signed scan.result
  v
WordPress callback: score, rank fixes, summary, PDF email, FluentCRM contact
```

WordPress owns visitors, consent, limits, scoring and storage. n8n owns orchestration, retries and
alerting. Laravel owns outbound HTTP to untrusted hosts, so the blast radius of that work is a small
container with no access to the WordPress database.

### Decisions

| Decision | Choice | Why |
|---|---|---|
| Scanner hostname | Path on `health.oldev.site`, proxied by the WordPress Apache to `scanner:8080` under `/scanner` | Only one DNS name is authorised. The route needs an API key on every request. |
| Scanner storage | SQLite on a named volume, database queue driver | No second MariaDB. Scanner rows are short lived. |
| Queue in WordPress | Action Scheduler, run by a worker loop inside the WordPress container | Works without site traffic and without a loopback request. |
| Signing in n8n | Crypto node `hmac` action with an n8n `crypto` credential | The instance forbids the `crypto` module in Code nodes, and secrets must not live in workflow JSON. |
| Signed bytes | The exact raw request body. Timestamp and nonce are inside the body | The Crypto node signs one value. Re-serialised JSON would not match. |
| Report URL | `/report/<uuid>/<sig>/` where `sig` is HMAC-SHA256 of the uuid | Unguessable, needs no stored token, cannot be derived from a database leak alone. |
| Claude API call | Raw HTTP through `wp_remote_post` | Keeps the plugin free of a vendored HTTP stack that could collide with other plugins, and uses the WordPress HTTP API seam for tests. |

## 2. Data model

All tables use the WordPress prefix and are created with `dbDelta`.

**`shr_scans`**: `id`, `uuid` (unique), `owner_id` (nullable), `url`, `domain`, `status`
(`queued`, `dispatched`, `complete`, `failed`), `overall_score`, `grade`, `category_scores` (JSON),
`summary`, `summary_source` (`ai`, `rules`), `raw_result` (JSON), `failure_reason`, `ip_hash`,
`attempts`, `consent_at`, `wants_pdf`, `pdf_sent_at`, `created_at`, `completed_at`.
Indexes: `uuid`, `owner_id`, `(domain, created_at)`, `(status, created_at)`, `created_at`.

**`shr_findings`**: `id`, `scan_id`, `check_key`, `category`, `outcome` (`pass`, `warn`, `fail`, `info`),
`severity` (`critical`, `high`, `medium`, `low`, `none`), `weight`, `rank_position`, `title`, `detail`,
`fix`. Indexes: `scan_id`, `(scan_id, rank_position)`.

**`shr_owners`**: `id`, `email` (unique), `pdf_consent_at`, `marketing_consent_at`, `created_at`.

**`shr_magic_links`**: `id`, `owner_id`, `token_hash` (unique), `expires_at`, `used_at`, `created_at`.

**`shr_usage`**: `(day, metric)` primary key, `used`. Atomic daily counters for `scans`, `emails`, `ai`.

**`shr_limits`**: `bucket` primary key (hashed key), `hits`, `window_end`. Sliding per-IP, per-domain and
per-address windows.

**`shr_nonces`**: `nonce` primary key, `expires_at`. Replay ledger.

**`shr_abuse_log`**: `id`, `event`, `ip_hash`, `domain`, `detail`, `created_at`.

The scanner keeps one table, `scans`: `id`, `scan_ref`, `url`, `status`, `result` (JSON), `error`,
timestamps, plus the Laravel `jobs` and `failed_jobs` tables. Rows are pruned after one day.

## 3. Signed message contracts

Signature header on both directions: `X-SHR-Signature: sha256=<hex>`, where the value is
HMAC-SHA256 of the raw request body with `SHR_HMAC_SECRET`. Every receiver:

1. Compares with a constant-time function.
2. Rejects a body with `issued_at` more than 300 seconds from its clock.
3. Records `nonce` in a ledger and rejects a repeat. WordPress uses `shr_nonces`; n8n uses workflow
   static data.

n8n saves static data when an execution ends, so a job replayed while the first run is still active gets
through to the pipeline. That costs a few extra lookups and nothing else: the scanner dedupes on `scan_ref`,
and WordPress, which holds the authoritative ledger, refuses a second result for a finished scan. A replay
after the run has finished is rejected by n8n.

### `scan.job`, WordPress to n8n

```json
{
  "v": 1,
  "type": "scan.job",
  "scan_id": "uuid",
  "url": "https://site.example/",
  "domain": "site.example",
  "callback_url": "https://health.oldev.site/wp-json/shr/v1/callback",
  "scanner_url": "https://health.oldev.site/scanner",
  "issued_at": 1793500000,
  "nonce": "32 hex chars"
}
```

n8n answers `202` after verifying, or `401` without doing any work.

### `scan.result`, n8n to WordPress

```json
{
  "v": 1,
  "type": "scan.result",
  "scan_id": "uuid",
  "status": "complete",
  "error": null,
  "issued_at": 1793500060,
  "nonce": "32 hex chars",
  "scanner": { "see": "scanner result below" },
  "pagespeed": { "score": 91, "lcp_ms": 1800, "cls": 0.02, "tbt_ms": 40, "error": null },
  "dns": {
    "spf": { "found": true, "record": "v=spf1 ...", "error": null },
    "dmarc": { "found": true, "record": "v=DMARC1; ...", "error": null },
    "dkim": { "found": true, "selectors": ["selector1"], "checked": 12, "error": null }
  }
}
```

`status` is `failed` when the scanner produced nothing. PageSpeed or DNS failures still complete: their
checks are marked unavailable and the category is left out of the overall score.

### Scanner API, n8n to Laravel

`X-Api-Key` header on every route, compared in constant time.

- `POST /api/v1/scans` with `{ "scan_ref": "uuid", "url": "https://..." }` returns `202` and `{ "id", "status" }`.
- `GET /api/v1/scans/{id}` returns `{ "id", "status", "result", "error" }`. Status is `queued`, `running`,
  `complete` or `failed`.

Result shape: `final_url`, `status_code`, `redirects`, `https`, `tls`, `headers`, `wordpress`, `robots`,
`sitemap`, `meta`.

## 4. Scan lifecycle

1. `POST /wp-json/shr/v1/scans` validates, then checks consent, per-IP limit, Turnstile, per-domain
   cooldown and the global daily cap, in that order. Input errors do not spend Turnstile verifications.
2. A row is stored as `queued` and `shr_dispatch_scan` is scheduled.
3. The action signs and posts `scan.job`. On failure it reschedules with backoff, up to 3 attempts.
4. n8n runs the checks and posts `scan.result`.
5. The callback verifies, stores findings and scores, then schedules `shr_finish_scan`.
6. `shr_finish_scan` writes the summary (Claude or rules), then, only with consent, sends the PDF and
   writes the FluentCRM contact.
7. A recurring watchdog fails scans stuck past 10 minutes. A daily job applies retention.

## 5. Scoring

Each check has a category, a weight and a severity. A pass earns the full weight, a warning half, a
failure none, and an unavailable check is excluded. A category score is earned weight divided by
available weight. The overall score is the weighted mean of the available categories:
security 30, TLS 15, SEO 20, email authentication 15, performance 15, WordPress 5.
The WordPress category only applies when WordPress was detected.

Grades: A 90 and up, B 80, C 70, D 60, F below. Fixes are ranked by severity, then weight.

## 6. Limits

| Limit | Default | Variable |
|---|---|---|
| Scans per IP per hour | 5 | `SHR_LIMIT_IP_HOUR` |
| Scans per IP per day | 20 | `SHR_LIMIT_IP_DAY` |
| Per-domain cooldown | 360 minutes | `SHR_DOMAIN_COOLDOWN_MINUTES` |
| Scans per day, global | 100 | `SHR_DAILY_SCAN_CAP` |
| Emails per day, global | 50, the code never allows more | `SHR_DAILY_EMAIL_CAP` |
| Emails per address per day | 3 | `SHR_LIMIT_EMAIL_ADDRESS_DAY` |
| AI calls per day | 25 | `SHR_DAILY_AI_CAP` |

Counters are single SQL statements, so concurrent requests cannot overshoot a cap.

## 7. Threat model

| Threat | Control |
|---|---|
| SSRF through the scan target | Scanner resolves every A and AAAA record first and rejects the host if any address is private or reserved. The connection is pinned to the checked address with `CURLOPT_RESOLVE`, which defeats DNS rebinding. Redirects are followed by hand and each hop is checked again. Ports 80 and 443 only. Numeric host forms such as `2130706433` and `0x7f.1` are rejected. WordPress runs the same checks early. |
| Scanner abused as a port scanner or fuzzer | Fixed request list on ports 80 and 443 only, 3 redirects at most, connect and total timeouts, 2 MB body limit. No credential testing, no exploit paths. |
| Scanning someone else's site in bulk | Consent box, Turnstile, per-IP limits, per-domain cooldown, global cap, abuse log. |
| Forged or replayed callback | HMAC over raw bytes, `hash_equals`, 300-second window, nonce ledger, and a scan that has finished cannot be overwritten. |
| Report URL enumeration | Random uuid plus HMAC. Constant-time check. `noindex`, `no-referrer`, `no-store` on the page. |
| Magic link theft or prefetch | Single use, 30-minute expiry, only the hash is stored. Opening the link shows a button, so a mail scanner's GET cannot burn it. |
| Account enumeration | The sign-in endpoint answers the same way whether or not the address exists. |
| CSRF on dashboard actions | `SameSite=Lax`, `HttpOnly`, `Secure` session cookie plus a session-bound token on every write. |
| Email bombing or Brevo exhaustion | 50 per day global, 3 per address, sends only with consent. |
| AI cost run-up | Daily cap. AI failure or a cap hit falls back to rule-based text. |
| Prompt injection from a scanned page | The Claude prompt contains only check keys, outcomes and scores that this code generated, never page text. |
| Stored XSS from page titles | Everything is escaped at output, and the PDF template escapes too. |
| Secret exposure | Environment variables only. `.env` is ignored. History is scanned before pushing. |
| SQL injection | `$wpdb->prepare` with `%i` for table names on every query. |
| Personal data held too long | 30-day retention job, delete-my-data flow, IPs stored only as keyed hashes. |

## 8. Out of scope

Payments, multisite, port scanning, credential testing, vulnerability exploitation, and anything not in
the brief.
