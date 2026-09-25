# Operations Guide (Phase 8)

How to run the CRM after go-live. Deployment and rollback: [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md). Security controls: [SECURITY.md](SECURITY.md).

Quick health: `php artisan app:production-check` (PASS / WARN / FAIL per area, never prints secret values; exit code 1 on any FAIL) and `GET /health`.

---

## 1. Queue workers

Queued work: Meta lead processing, telephony callbacks/recording archive/reconciliation, notifications (mail, push, in-app), report exports. `QUEUE_CONNECTION` must be `database` (default) or `redis`; `sync` would run all of this inside web requests and is flagged FAIL by `app:production-check`.

Queues: `default` and `integrations` (`META_QUEUE`, `TELEPHONY_QUEUE`). One worker processing both in priority order is enough for a typical team; add a second process if `integrations` backs up.

```bash
php artisan queue:work --queue=default,integrations --tries=1 --timeout=120 --sleep=3 --max-time=3600
```

`--tries=1`: Meta and telephony retries are managed by their own event ledgers (backoff, max attempts, visible in the Integration health screens), not by the worker. `--max-time` recycles the process hourly (memory hygiene); the supervisor restarts it.

**Supervisor** (`/etc/supervisor/conf.d/salescrm-worker.conf`):

```ini
[program:salescrm-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/salescrm/artisan queue:work --queue=default,integrations --tries=1 --timeout=120 --sleep=3 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=150
redirect_stderr=true
stdout_logfile=/var/www/salescrm/storage/logs/worker.log
```

`supervisorctl reread && supervisorctl update && supervisorctl start salescrm-worker:*`

**systemd** (`/etc/systemd/system/salescrm-worker.service`):

```ini
[Unit]
Description=Sales CRM queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/salescrm/artisan queue:work --queue=default,integrations --tries=1 --timeout=120 --sleep=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

`systemctl enable --now salescrm-worker`

**cPanel / shared hosting** (no long-running processes): add a cron entry every minute that drains the queue and exits:

```
* * * * * cd /home/USER/salescrm && php artisan queue:work --queue=default,integrations --tries=1 --timeout=50 --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

Latency is then up to one minute; acceptable for reminders, but Meta leads and call callbacks appear with that delay.

**Windows (WAMP / on-premise):** browser push needs `OPENSSL_CONF` in the **worker's** environment, or every push fails with an `OPENSSL_CONF` error in the log. PHP reads it at startup, so set it before starting the worker, or system-wide for services and Task Scheduler jobs:

```powershell
$env:OPENSSL_CONF = "C:\wamp64\bin\php\php8.3.6\extras\ssl\openssl.cnf"
php artisan queue:work --queue=default,integrations --tries=1 --timeout=120 --sleep=3 --max-time=3600
# scheduler: Task Scheduler running "php artisan schedule:run" every minute, or during development:
php artisan schedule:work
```

Browser push jobs retry temporary push-service errors themselves (30 s, then 120 s) regardless of `--tries=1`.

**Notification delivery check:** `php artisan notifications:doctor [--user=email] [--send-test=lead|reminder]` reports each link separately: scheduler heartbeat, unclaimed due reminders, reminders stuck in processing, waiting jobs, VAPID and admin switches, OpenSSL, and the user's subscriptions. It never prints endpoints or keys.

**Every deploy:** `php artisan queue:restart` (workers finish their current job and restart on the new code).

### Failed jobs

Failed jobs are handled from the CLI only; there is no UI, so job payloads (which can contain identifiers) are never shown in the browser.

| Command | Purpose |
|---------|---------|
| `php artisan queue:failed` | List failed jobs (id, queue, class, failed at) |
| `php artisan queue:retry <id>` / `queue:retry all` | Re-queue after fixing the cause |
| `php artisan queue:forget <id>` | Delete one failed job |
| `php artisan queue:flush` | Delete all failed jobs (only after review) |
| `php artisan queue:prune-failed --hours=720` | Optional housekeeping |

Meta webhook events have their own ledger with a **Retry** action (Integrations → Facebook → Webhook events) and automatic retries (`meta:retry-failed`); calls stuck in a non-final state are closed by `telephony:reconcile-pending`. Prefer those over `queue:retry` for integration issues.

## 2. Scheduler

Cron (the only cron entry the app needs):

```
* * * * * cd /var/www/salescrm && php artisan schedule:run >> /dev/null 2>&1
```

Inventory (`routes/console.php`; all use `withoutOverlapping`). Times are in the **application timezone (UTC)**; 03:15 UTC = 08:45 IST.

| Task | Frequency | Purpose |
|------|-----------|---------|
| `scheduler-heartbeat` | every minute | Writes a heartbeat read by `app:production-check` (WARN if never seen, FAIL if older than 5 minutes) |
| `followups:dispatch-reminders` | every minute | Follow-up reminders and overdue alerts |
| `meetings:dispatch-reminders` | every minute | Meeting reminders |
| `meta:retry-failed` | every 10 minutes | Retries failed/pending Meta lead events within the backoff policy |
| `telephony:reconcile-pending` | every 5 minutes | Closes calls whose final Exotel callback was missed (queries Exotel) |
| `reports:prune-exports` | hourly | Deletes report export files older than `report.export_retention_hours` (default 24) |
| `meta:check` | daily 03:15 | Token health (expiring / expired), page subscription check |
| `meta:prune-events` | daily 03:45 | Removes completed Meta events older than `facebook.event_retention_days` (180) |
| `telephony:prune` | daily 04:15 | Recording retention and telephony event retention |

`php artisan schedule:list` shows the next run of each task.

## 3. Timezones

| Layer | Setting |
|-------|---------|
| Application / PHP | `app.timezone = UTC` (fixed; all timestamps are stored in UTC) |
| MySQL session | `DB_TIMEZONE=+00:00` |
| Display, reports, "today", working hours, reminders | CRM timezone setting (Settings → General, default `Asia/Kolkata`) |
| Exotel timestamps | `EXOTEL_TIMEZONE` (Exotel reports IST for Indian accounts) |

The server OS timezone does not matter. Do not change `app.timezone` after go-live.

## 4. Logging

- Channel: `LOG_STACK=daily`, rotated daily, kept `LOG_DAILY_DAYS` days (default 14) in `storage/logs/`. `LOG_LEVEL=warning` in production.
- Both `single` and `daily` pass through `App\Logging\RedactSecrets` (tokens, secrets, passwords, Authorization headers are masked). Add the tap to any other channel you enable (e.g. `syslog`, `papertrail`).
- If logrotate is used instead, do not run both on the same files.
- **Audit logs are separate** (database table `audit_logs`, shown in Admin → Audit logs). They are **never pruned automatically** and are not affected by log rotation. Any audit retention policy must be an explicit client decision implemented as a reviewed change.
- Supervisor worker output (`storage/logs/worker.log`) is not rotated by Laravel; add it to logrotate.

## 5. Backups and restore

Nothing in the application performs backups; they are an infrastructure responsibility. What to back up:

| Item | Why |
|------|-----|
| MySQL database | All CRM data, audit logs, settings, encrypted tokens |
| `storage/app/private/` | Lead attachments, archived recordings (if `private_storage`), report exports (transient) |
| `storage/app/branding/` | Logo and favicon |
| `.env` | Store **separately and encrypted** (password manager / secrets vault). Without the same `APP_KEY`, encrypted tokens and push subscriptions cannot be decrypted |

Retention policy: **7 daily, 4 weekly, 3 monthly**, stored off-server (different provider or region), encrypted at rest.

Example nightly job (cron `30 20 * * *` = 02:00 IST), using a MySQL option file so the password is not on the command line:

```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date +%F); DIR=/backups/salescrm; mkdir -p "$DIR"/{daily,weekly,monthly}
mysqldump --defaults-extra-file=/root/.salescrm.my.cnf --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 salescrm | gzip > "$DIR/daily/db-$STAMP.sql.gz"
tar -czf "$DIR/daily/files-$STAMP.tar.gz" -C /var/www/salescrm storage/app/private storage/app/branding
[ "$(date +%u)" = 7 ] && cp "$DIR/daily/"*"-$STAMP."* "$DIR/weekly/"
[ "$(date +%d)" = 01 ] && cp "$DIR/daily/"*"-$STAMP."* "$DIR/monthly/"
find "$DIR/daily"   -type f -mtime +7   -delete
find "$DIR/weekly"  -type f -mtime +28  -delete
find "$DIR/monthly" -type f -mtime +92  -delete
# then sync $DIR to off-site storage (rclone / aws s3 sync / provider snapshot)
```

Managed databases (RDS, DigitalOcean, cPanel backups) may replace the dump; the file backup is still needed. Enable binary logs if the client requires point-in-time recovery.

Take an extra backup **before every deployment** that contains migrations.

### Restore drill (staging only)

Run at go-live and then quarterly. **Never restore over production** as a test.

1. Provision staging with the same release tag and a copy of production `.env` whose `APP_URL`, mail, Meta and Exotel values are replaced with staging values (keep `APP_KEY` so encrypted data can be read), and `MAIL_MAILER` pointed at a capture service.
2. `gunzip < db-YYYY-MM-DD.sql.gz | mysql --defaults-extra-file=... salescrm_staging`
3. Extract the files archive into `storage/app/`.
4. `php artisan migrate --force` (only if staging runs a newer release), `optimize:clear`, re-cache, `queue:restart`.
5. **Disable outbound side effects before starting workers**: disconnect Meta and disable telephony in the staging admin, or leave their env credentials empty, so the restored copy does not call real customers or post to Meta.
6. Verify: log in as each role, counts of leads/follow-ups/calls match the source, attachments open, audit log intact, `/health` ok.
7. Record duration and data age (RTO/RPO) in the go-live log. Restrict access: a restored copy is production data.

A real production restore follows the same steps after `php artisan down`, stopping workers and cron, with the client's written approval of the data-loss window.

## 6. Monitoring

| Signal | How |
|--------|-----|
| Uptime | External monitor on `GET /health` (200 `{"status":"ok"}` / 503 `fail`; checks app, database, cache; no versions or config exposed; throttled 60/min per IP) |
| Queue | Worker process up (Supervisor/systemd); `app:production-check` FAILs on jobs waiting > 15 minutes, WARNs on failed jobs |
| Scheduler | Heartbeat via `app:production-check` |
| Integrations | Super Admin dashboard widget and Integration health cards (Meta token state, failed events, telephony health check) |
| Errors | `storage/logs/laravel-*.log` at `warning`+; optionally ship to a log service via an extra channel with the redaction tap |
| Disk | Alert at 80 % of the volume holding `storage/` and MySQL |

Suggested schedule: run `php artisan app:production-check` from cron daily and email non-zero exit codes to the operator, or wire it into existing monitoring.

## 7. Disk capacity

Recordings use CRM disk only when **Settings → Telephony → recording storage = `private_storage`**. In `provider` mode they are streamed from Exotel and use no CRM disk.

```
Recording disk (GB) ≈ agents × connected calls per agent per day × average talk minutes
                      × MB per minute × retention days ÷ 1024
```

Measure MB per minute from a real archived recording (typical telephony MP3 is ~0.1–0.5 MB/min). Example: 20 agents × 30 connected calls × 3 min × 0.25 MB × 180 days ÷ 1024 ≈ **79 GB**. Add 30 % headroom. Shorter retention (`Keep recordings for`) or `provider` mode reduces this; `telephony:prune` deletes expired audio daily.

Other storage: attachments (≤ 10 MB each), report exports (deleted after 24 h), logs (14 days), database (roughly 1–3 GB per 100k leads with their history; check `information_schema.tables` quarterly).

## 8. Retention settings

| Setting | Default | Effect |
|---------|---------|--------|
| `telephony.recording_retention_days` | 180 | Recording audio + provider reference removed; call record kept |
| `telephony.event_retention_days` | 90 | Processed telephony callback events removed |
| `facebook.event_retention_days` | 180 | Completed Meta webhook events removed (failed/pending kept) |
| `report.export_retention_hours` | 24 | Export files deleted |
| Audit logs | never | No automatic pruning |
| Leads, follow-ups, meetings, calls | never | Business records are not pruned |

## 9. Meta token expiry

`meta:check` runs daily (`debug_token` plus Page subscription check). A long-lived user token lasts about 60 days. Within 7 days of expiry the connection shows **Token Expiring**; once Meta rejects it, **Needs Reauthorization** (dashboard widget and Integration health). Webhooks are still received and recorded, but lead details cannot be fetched, so those events fail with an authentication category. Action: Super Admin → Integrations → Facebook → Reconnect, then **Retry** the failed events from the Webhook events screen and, if needed, **Sync recent leads** per form (up to 90 days). Prefer a Business Manager System User token to avoid expiry. See [FACEBOOK_INTEGRATION.md §2 and §8](FACEBOOK_INTEGRATION.md#8-retries-failures-and-backfill).

## 10. Dependencies

- `composer audit`: no advisories at 1.0.0.
- `npm audit`: 2 moderate advisories in `vitest` / `@vitest/mocker` (dev/test tooling only; not shipped in `public/build`). The fix is a major upgrade (Vitest 4), deferred to a planned tooling update rather than upgraded blindly.
- Update policy: update dependencies on a branch, run the full test suite and build, test on staging, then deploy the new `composer.lock` / `package-lock.json`. Never `composer update` on the server.

## 11. Performance

Measured on a developer laptop with `php artisan crm:seed-performance` (5,000 leads, 10,000 follow-ups, 5,000 meetings, 20,000 calls; local/testing only, `--purge` removes the generated rows):

| Page | Result |
|------|--------|
| Lists (leads, follow-ups, meetings, calls) | 0.1–0.6 s, ~20 queries, paginated (25/50/100), no N+1 |
| Dashboard | admin 1.6–2.0 s, manager 1.3 s, executive 0.7 s |
| Reports overview (all data) | ~1.8 s |
| Pipeline / calendar | Bounded (fixed cards per column; calendar range-limited) |

All measured queries use indexes. The admin dashboard and reports overview are the known hot spots (many aggregate queries; response-time medians); they were not refactored in Phase 8 to avoid changing report figures. If production data is much larger, profile these first (and consider a Redis cache store).

## 12. Common tasks

| Task | Command |
|------|---------|
| Create the first Super Admin | `php artisan crm:create-super-admin` (refuses if one already exists) |
| Change the Super Admin's name, email or password | `php artisan crm:reset-super-admin` (hidden prompt; signs out its sessions; audited) |
| Verify the data is clean (counts only) | `php artisan crm:production-data-check` before go-live, `--live` afterwards |
| Reset a user's password | Admin → Users → the user → reset (never share passwords over chat) |
| Maintenance mode | `php artisan down --render="errors::503" --retry=60` / `php artisan up` |
| Clear caches after `.env` change | `php artisan config:cache` then `php artisan queue:restart` |
| Readiness | `php artisan app:production-check` |
