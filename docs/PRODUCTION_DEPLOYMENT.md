# Production Deployment & Go-Live Runbook (Phase 8)

Release **1.0.0** (`config/crm.php`, shown to admins in System settings).
Day-to-day running (queues, scheduler, logs, backups, monitoring) is in [OPERATIONS.md](OPERATIONS.md); user acceptance in [UAT_CHECKLIST.md](UAT_CHECKLIST.md); controls in [SECURITY.md](SECURITY.md).

> **DO NOT USE DEMO CREDENTIALS IN PRODUCTION.** The demo accounts (`*@salescrm.local`, shared password) exist only for local development. The demo seeders throw an exception outside `local`/`testing`, and `app:production-check` fails if any `@salescrm.local` account exists.

---

## 1. Server requirements

| Component | Requirement |
|-----------|-------------|
| PHP | 8.2+ (8.3 tested) with `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `fileinfo`, `curl`; `gmp` recommended (faster Web Push encryption) |
| Database | MySQL 8.0+ or MariaDB 10.6+, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, strict mode |
| Web server | nginx or Apache with TLS; document root = `public/` |
| Process control | Supervisor, systemd, or cPanel cron-based worker (see [OPERATIONS.md §1](OPERATIONS.md#1-queue-workers)) |
| Cron | `* * * * * php /path/to/app/artisan schedule:run >> /dev/null 2>&1` |
| Node | 20+ **on the build machine only** (assets are built, not run, in production) |
| Disk | App + logs ~1 GB; plus attachments and (if archived) recordings — see [OPERATIONS.md §7](OPERATIONS.md#7-disk-capacity) |

## 2. MySQL

```sql
CREATE DATABASE salescrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'salescrm'@'localhost' IDENTIFIED BY '<generated password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON salescrm.* TO 'salescrm'@'localhost';
```

- Never use `root`. `DROP` is needed only by migrations; it can be revoked between releases if the DBA prefers.
- Laravel connects with `strict = true` and a session `time_zone = '+00:00'` (`DB_TIMEZONE`), so the server's own `sql_mode` / `time_zone` do not change application behaviour. `app:production-check` verifies strict mode, `utf8mb4` and the session time zone.
- Enable binary logging if point-in-time recovery is wanted (see [OPERATIONS.md §5](OPERATIONS.md#5-backups-and-restore)).

## 3. Environment

Copy `.env.example` to `.env` **on the server** and fill it in. The template is production-safe by default and contains empty placeholders only. Never commit `.env`, and never paste its values into tickets, chat or docs.

Release-blocking values:

| Key | Production value |
|-----|------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | `php artisan key:generate` **once**; never change it afterwards (it decrypts tokens, subscriptions and recordings references) |
| `APP_URL` | `https://crm.client-domain` |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` (or `redis`) — never `sync` |
| `MAIL_MAILER` | `smtp` with host, port, credentials and a real `MAIL_FROM_ADDRESS` |
| `TELEPHONY_DRIVER` | `exotel` |
| `META_ALLOW_MANUAL_TOKEN` | `false` |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `warning` |
| `TRUSTED_PROXIES` | Only when a load balancer / proxy terminates TLS (its IP/CIDR, or `*` for a single local proxy) |

Integrations (Meta, Exotel, VAPID) are filled in during §8–§9. CSP extras (`CSP_*`) are in [SECURITY.md](SECURITY.md#content-security-policy).

## 4. Web server and HTTPS

HTTPS is mandatory: WebRTC microphone access, secure cookies, Meta and Exotel callbacks all require it.

nginx (TLS termination on the same host):

```nginx
server {
    listen 80;
    server_name crm.client-domain;
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl http2;
    server_name crm.client-domain;
    root /var/www/salescrm/public;
    index index.php;
    client_max_body_size 12m;          # attachments are capped at 10 MB by the app

    ssl_certificate     /etc/letsencrypt/live/crm.client-domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.client-domain/privkey.pem;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

Apache/cPanel: point the domain's document root at `public/` (the shipped `public/.htaccess` handles routing) and enable "Force HTTPS Redirect". Security headers and CSP are sent by the application, so no web-server header configuration is needed; do not add a second, conflicting CSP at the web-server level.

## 5. First deployment

Build on a CI/build machine (or locally), never with dev tooling on the server:

```bash
git clone <repo> salescrm && cd salescrm
git checkout v1.0.0
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build            # produces public/build; node_modules is not deployed
```

Upload the tree (without `node_modules`, `.env`, `storage/app/*` contents) to the server, then on the server:

```bash
cp .env.example .env && nano .env        # fill in §3
php artisan key:generate                 # first deployment only
php artisan migrate --force             # on a NEW, EMPTY database — never a copy of a local/demo database
php artisan db:seed --class=ProductionSeeder --force   # system data only (roles, permissions, settings, reference data)
php artisan crm:create-super-admin       # interactive; password is prompted, never passed as an argument
php artisan crm:production-data-check    # counts only; must end with "Clean: system configuration and Super Admin only."
php artisan webpush:vapid                # optional browser push; paste keys into .env
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache
```

Start the queue worker and cron ([OPERATIONS.md §1–2](OPERATIONS.md#1-queue-workers)), then:

```bash
php artisan app:production-check         # must report 0 failures
curl -s https://crm.client-domain/health # {"status":"ok",...}
```

`db:seed` is idempotent: rerunning it inserts missing settings/permissions without overwriting admin changes. Do **not** set `SUPER_ADMIN_PASSWORD` in production; `DatabaseSeeder` ignores it there.

### 5.1 Clean go-live data (no demo data, one Super Admin)

Go live on a **fresh database**. Never deploy a database that has held development, demo or test transactional data.

- `ProductionSeeder` (also called by `DatabaseSeeder` in every environment) seeds only roles, permissions, role permissions, lead statuses, sources, lost reasons, follow-up types, meeting types, call dispositions and setting defaults. It creates no users and no Meta or telephony configuration, and it is idempotent.
- Demo seeders (`DemoSeeder` and the module demo seeders) stay in the source for local development only. They throw outside `local` and skip in `testing`. `DatabaseSeeder` calls them only when `APP_ENV=local`.
- `crm:create-super-admin` is the only way to create the first account. It refuses to create a second Super Admin. To change the Super Admin's name, email or password, run `php artisan crm:reset-super-admin`: it prompts for the new values with a hidden password prompt, validates them, signs out that account's sessions and writes an audit entry. It has no password option and never prints the password.
- `crm:production-data-check` prints counts only (no names, emails or customer data). It fails unless there is exactly one user who is an active Super Admin, every system table has rows, and every operational table (leads, enquiries, notes, follow-ups, meetings, calls, recordings, Meta and telephony integrations, campaigns, assignment rules, teams, activities, attachments, notifications, push subscriptions, report exports, login history, sessions, audit log, number sequences, queued/failed jobs, operational files) is empty. It always fails on demo markers: `*@salescrm.local` accounts, demo lead names, fake telephony integrations, and demo teams or campaigns. After go-live, run it with `--live`, which allows real business rows but still fails on demo markers.
- `app:production-check` also fails if any `@salescrm.local` account exists.

**Cleaning an existing staging/local database.** `php artisan crm:clear-demo-data` shows the row counts, then asks you to type `DELETE-DEMO-DATA`. It refuses to run in production unless `--force-production` is passed, and it still asks for the phrase. Take a database backup first. The command:

1. Deletes rows children-first inside one transaction, with foreign-key checks left on. Self-references (rescheduled meetings/follow-ups, duplicate leads, user manager/team) are cleared first.
2. Removes users identified by the explicit demo email list or the `@salescrm.local` domain (never by ID). `--all-users` removes every non-Super-Admin account. Super Admins are always kept, and remaining users' remember tokens are cleared.
3. Clears these operational tables: Meta webhook events, field mappings, forms, pages and integrations; call events, recordings, calls, telephony users, numbers and integrations; meeting reminders, participants and meetings; follow-up reminders and follow-ups; lead note histories, notes, custom field values, status changes, assignments, enquiries, attachments, activities and leads; assignment rules and campaigns; notifications, push subscriptions and report exports; login histories, sessions, password reset tokens and the audit log; jobs, job batches and failed jobs; number sequences, so the first real records are `LD-YYYY-000001`, `MT-YYYY-000001` and `CALL-YYYY-000001`; team users and teams.
4. Deletes the files referenced by those rows and the private directories `leads/`, `call-recordings/`, `report-exports/`, `exports/`, `imports/` and `tmp/` on the `local` disk (`storage/app/private`). The `branding` disk (client logo and favicon) is never touched.
5. Keeps roles, permissions, lookups, settings, custom field definitions and branding. Auto-increment IDs are not reset.

Clearing the audit log and number sequences is a **one-time, pre-go-live** step. Never run `crm:clear-demo-data` against a live CRM, and never reset sequences after go-live.

## 6. Subsequent deployments (release procedure)

```bash
# on the build machine
git checkout vX.Y.Z
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

# on the server
php artisan down --render="errors::503" --retry=60   # static maintenance page, no DB needed
# (take a database backup first — OPERATIONS.md §5)
# sync the new code over the old (keep .env and storage/)
php artisan migrate --force
php artisan db:seed --class=CoreSeeder --force       # only when the release notes say new permissions/settings were added
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart                            # workers pick up the new code after their current job
php artisan up
php artisan app:production-check
```

Rules:

- **Never run `composer update` on production.** Only `composer install` from the committed `composer.lock`.
- Review `php artisan migrate --pretend` output before migrating when the release contains migrations.
- `queue:restart` is required on every deploy; long-running workers otherwise keep executing the old code.
- Maintenance mode stops web traffic only; the scheduler keeps running unless you pause cron. Webhooks received during maintenance get 503 and are retried by Meta/Exotel.

## 7. Rollback runbook

Choose the least destructive option that restores service. **Do not blindly run `php artisan migrate:rollback` after production data has changed** — `down()` methods drop columns/tables and would destroy data written since the release.

| Situation | Action |
|-----------|--------|
| Bad code, **no migrations** in the release | `php artisan down`; redeploy the previous tag's code and `public/build`; `optimize:clear`, re-cache, `queue:restart`, `up` |
| Bad code, migrations were **additive** (new tables/nullable columns) | Redeploy previous code only. Old code ignores new columns/tables. Leave the schema in place; fix forward in the next release |
| Migration **failed half-way** | `php artisan down`. Inspect `migrations` table and the schema. Fix forward with a corrective migration if possible. Only if the failed migration's changes are confirmed empty (no production writes) may that single step be reverted, with `--step=1`, after a backup |
| Data corrupted by the release | Stop writes (`down`, stop workers). Restore the pre-deploy backup **to staging first**, verify, then plan the production restore with the client (OPERATIONS.md §5). Accept the loss window explicitly |
| Config error | Fix `.env`, `php artisan config:cache`, `queue:restart` |

After any rollback: `app:production-check`, `/health`, smoke-test login, lead list, a call and a report as each role.

## 8. Meta Lead Ads go-live

Full detail: [FACEBOOK_INTEGRATION.md §1 and §10](FACEBOOK_INTEGRATION.md#10-production-deployment-checklist).

- [ ] Meta app in **Live** mode, Business Verification done, App Review approved for `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`, `pages_manage_ads`, `leads_retrieval`
- [ ] Valid OAuth redirect URI = `https://crm.client-domain/admin/integrations/facebook/callback`
- [ ] Page webhook `leadgen` → `https://crm.client-domain/webhooks/meta/leads`, verify token = `META_WEBHOOK_VERIFY_TOKEN` (random, ≥ 32 chars)
- [ ] `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN` set; `META_ALLOW_MANUAL_TOKEN=false`; `config:cache`
- [ ] Super Admin → Integrations → Facebook → Connect (completes with the CSP enforced), select Pages, subscribe, map each form
- [ ] **Live test lead**: submit via Meta's Lead Ads Testing Tool for each mapped form; confirm the lead appears, is assigned by the rules, the assignee is notified, and `FACEBOOK_LEAD_CREATED` is audited
- [ ] Token expiry: the long-lived user token lasts ~60 days. `meta:check` (daily) moves it to *Token Expiring* (7 days before) / *Needs Reauthorization* and the Integration health card + dashboard widget warn admins. Reconnect before expiry; a System User token (Business Manager) avoids expiry and is the recommended production setup

## 9. Exotel telephony go-live

Full detail: [TELEPHONY_MODULE.md §17](TELEPHONY_MODULE.md#17-production-setup).

- [ ] **Release-blocking:** verify `resources/js/Composables/useTelephony.js` (`exotelDriver`) against the Exotel CRM Web SDK **version enabled on the client's account**: constructor arguments, `Initialize`, `MakeCall`, event names (incoming / connected / ringing / ended), mute/hold. The adapter was built from the public documentation; the SDK is loaded from `EXOTEL_WEBRTC_SDK_URL`
- [ ] `EXOTEL_*` values set (see `.env.example`), `EXOTEL_SUBDOMAIN` matches the account cluster; `config:cache`
- [ ] Exotel flow: status callback and Passthru URLs with `?token=<EXOTEL_WEBHOOK_SECRET>`
- [ ] Admin → Telephony: ExoPhones, default number, one calling account per agent, integration enabled, health check green
- [ ] Staging tests (with real numbers): outbound click-to-call answered / busy / no answer; inbound call routed to the right agent and lead; missed call appears; recording available and playable only by permitted roles; disposition required after connected calls; reconciliation closes a call whose final callback was blocked
- [ ] Browser calling with CSP: first on staging with `CSP_REPORT_ONLY=true`, console open; add any reported Exotel host to `CSP_EXTRA_CONNECT_SRC`; then enforce
- [ ] **Recording consent**: announcement in the Exotel flow and legal sign-off (DPDP Act 2023 / sector rules) *before* enabling recording
- [ ] **Retention** (`Keep recordings for`, default 180 days) and **storage mode** (`provider` = stream from Exotel, no CRM disk; `private_storage` = copy to CRM disk, size it with [OPERATIONS.md §7](OPERATIONS.md#7-disk-capacity)) agreed with the client

## 10. Client configuration checklist

Done by the client's Super Admin after §5 (record who did what in the go-live log):

- [ ] **Settings → General**: company name, timezone (default Asia/Kolkata), logo and favicon (the "Powered by Buildify360" credit is fixed and not configurable)
- [ ] **Settings → Security**: minimum password length (default 12), failed logins before lockout
- [ ] **Users** (names, roles); give each person their own account; deactivate, never share. There are no teams: access is Own / All (Admins see everything, everyone else sees leads assigned to them)
- [ ] **Roles & Permissions**: review defaults ([PERMISSIONS.md](PERMISSIONS.md)); grant `report.export` / `file.download` only to named managers if the client wants it
- [ ] **Lead Settings**: statuses (won/lost flags), sources, lost reasons, campaigns; **Custom Fields**
- [ ] **Assignment Rules** for Facebook/manual leads
- [ ] **Follow-up / Meeting Settings**: types, reminder offsets, working hours
- [ ] **Telephony** (§9) and **Facebook** (§8)
- [ ] **Reports**: first-response target, neglected-lead days, export retention
- [ ] Lead value stays hidden unless the client asks (`CRM_LEAD_VALUE_ENABLED`)
- [ ] Browser notifications: users opt in from their profile. Requires **HTTPS** (service workers and Web Push don't work over plain HTTP except on localhost), VAPID keys, the scheduler and the queue worker. On a Windows server the worker needs `OPENSSL_CONF` in its environment. `php artisan notifications:doctor` must show all PASS. Then run the manual Chrome/Edge checks in [BROWSER_NOTIFICATIONS.md](BROWSER_NOTIFICATIONS.md#manual-windows-verification-chrome-and-edge)

## 11. Staging environment

Staging mirrors production (same PHP/MySQL versions, HTTPS, queue worker, cron) on a separate server or vhost and a **separate database**:

- `APP_ENV=staging`, `APP_DEBUG=false`, its own `APP_KEY`, its own Meta app (development mode) or test Page, Exotel sandbox numbers or a restricted ExoPhone
- `TELEPHONY_DRIVER=exotel` (the fake driver refuses to run on staging, by design)
- Mail: a capture service (Mailpit/Mailtrap) so no real customer receives email
- `CSP_REPORT_ONLY=true` while verifying browser calling and Meta OAuth, then `false`
- Data: UAT users created with `crm:create-super-admin` + the Users screen. Production data may be restored to staging only for restore drills, and must then be access-restricted as production. Performance tests use `php artisan crm:seed-performance` (local/testing only — run it on a local copy, not on staging)
- Walk through [UAT_CHECKLIST.md](UAT_CHECKLIST.md) on staging before go-live

## 12. Go-live sequence

1. Staging sign-off (UAT checklist signed per role).
2. Backup plan tested (restore drill on staging, OPERATIONS.md §5).
3. Production deploy (§5), `app:production-check` = 0 failures, `/health` ok.
4. Client configuration (§10).
5. Meta live test lead (§8) and a real call (§9).
6. Monitoring on (OPERATIONS.md §6); first-week daily review of failed jobs, integration health and logs.
