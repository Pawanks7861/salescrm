# Sales CRM

Laravel 12 · Vue 3 · Inertia · Tailwind · MySQL. Lead management for Facebook Lead Ads with strict RBAC, audit logging, follow-ups, meetings with a calendar, conflict detection and reminders, Exotel telephony (click-to-call, browser calling, call recording), and permission-scoped reporting and analytics.

Architecture and design: [docs/CRM_ARCHITECTURE.md](docs/CRM_ARCHITECTURE.md) (plus `DATABASE_SCHEMA`, `PERMISSIONS`, `SECURITY`, `FACEBOOK_INTEGRATION`, `MEETING_MODULE`, `LEAD_MODULE`, `FOLLOWUP_MODULE`, `TELEPHONY_MODULE`, `REPORTING_MODULE`, `BROWSER_NOTIFICATIONS` in `docs/`).

**Access model: Own / All.** Admins and Super Admins (`*.view_all`) see everything, including unassigned leads. Every other user, including Sales Managers by default, sees only leads assigned to them and the follow-ups, meetings and calls on those leads (or assigned to them). There is no team-based visibility. The Teams screens were removed. Legacy `teams` / `team_users` tables and `team_id` / `manager_id` columns are kept but unused. See [docs/CRM_ARCHITECTURE.md §2.4](docs/CRM_ARCHITECTURE.md#24-access-model-own--all-team-visibility-removed).

Release **1.0.0**. Going live: [docs/PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md) · running it: [docs/OPERATIONS.md](docs/OPERATIONS.md) · acceptance: [docs/UAT_CHECKLIST.md](docs/UAT_CHECKLIST.md).

## Requirements

- PHP **8.2+** (WAMP: use `C:\wamp64\bin\php\php8.3.6` — the default CLI PHP on this machine is 8.1)
- MySQL 8 (InnoDB is forced in `config/database.php`)
- Node 20+

## Setup

```powershell
$env:Path = "C:\wamp64\bin\php\php8.3.6;" + $env:Path   # WAMP only
composer install
npm install
copy .env.example .env
# .env.example is production-safe; for local development set APP_ENV=local, APP_DEBUG=true,
# APP_URL=http://localhost, SESSION_SECURE_COOKIE=false, LOG_LEVEL=debug, MAIL_MAILER=log and DB_USERNAME/DB_PASSWORD
php artisan key:generate
# create the `salescrm` database (utf8mb4_unicode_ci), then:
php artisan migrate --seed
npm run build            # or `npm run dev` while developing
php artisan serve
```

Seeding always runs `ProductionSeeder` (permissions, the four system roles, default settings and lookup data; no users).
Only in the `local` environment does it also create a Super Admin (`SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD`; a random password is printed if not set) and the demo data.
Everywhere else, create the first Super Admin with `php artisan crm:create-super-admin` and change it later with `php artisan crm:reset-super-admin`
(see [docs/PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md) §5.1). To empty a local/staging database before go-live, run `php artisan crm:clear-demo-data`, then `php artisan crm:production-data-check`.

**Local development only** — demo accounts below use a shared password. **DO NOT USE DEMO CREDENTIALS IN PRODUCTION.**
The demo seeders throw an exception outside `local`/`testing`.
In the `local` environment demo users are also created (`admin@`, `manager@`, `rahul@`, `priya@salescrm.local`, password `Password@123`) together with 26 demo leads, campaigns, assignment rules, demo follow-ups (overdue, today, upcoming, completed with a next follow-up) and demo meetings (today, tomorrow, rescheduled, completed, cancelled, internal). Reporting demo data adds another executive (`arjun@salescrm.local`), historical calls, a repeat Meta enquiry and a no-show meeting (local only; `ReportingDemoSeeder`).

After adding permissions to `App\Support\Permissions` or settings to `App\Support\SettingDefinitions`, re-run:

```powershell
php artisan db:seed --class=CoreSeeder
```

## Background processing

Follow-up and meeting reminders need **both** the scheduler and a queue worker (`QUEUE_CONNECTION=database`):

```powershell
php artisan queue:work --queue=default,integrations --tries=1  # reminders, notifications, Meta lead processing, call recordings
php artisan schedule:work         # reminders every minute; telephony:reconcile-pending every 5 min; meta:retry-failed every 10 min; meta:check / meta:prune-events / telephony:prune daily (local dev)
```

Production cron (every minute): `* * * * * cd /path/to/salescrm && php artisan schedule:run >> /dev/null 2>&1`, plus a supervised `queue:work` (Supervisor / systemd / cPanel examples and failed-job commands in [docs/OPERATIONS.md](docs/OPERATIONS.md)). Run `php artisan queue:restart` on every deploy.

Manual reminder run: `php artisan followups:dispatch-reminders` / `php artisan meetings:dispatch-reminders`, then `php artisan queue:work --stop-when-empty`. Running the commands repeatedly never sends a reminder twice, and rescheduled, cancelled or completed meetings never fire their old reminders. Overdue follow-ups are calculated at read time — there is no job that marks them overdue. See [docs/FOLLOWUP_MODULE.md](docs/FOLLOWUP_MODULE.md) and [docs/MEETING_MODULE.md](docs/MEETING_MODULE.md).

## Meetings and calendar

`/meetings` (list), `/meetings/{id}` (detail) and `/calendar` (month / week / day / agenda; click a slot to schedule, drag to reschedule). Meeting types and rules: Admin → Meeting Settings. Meetings follow lead visibility; there is no meeting export or `.ics` download.

## Meta / Facebook Lead Ads

Admin → Integrations → Facebook (`facebook.manage`, Super Admin by default). Set `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN` and `META_GRAPH_VERSION` (default `v25.0`) in `.env`; the secret and verify token are never stored in the database. Then:

1. Click **Connect with Facebook**.
2. Enable Pages.
3. Review the forms and their field mapping.

The webhook URL is `https://YOUR-CRM/webhooks/meta/leads` (Page object, `leadgen` field). It requires HTTPS, and processing runs on the `integrations` queue.

Commands: `meta:sync`, `meta:check`, `meta:retry-failed`, `meta:prune-events`. For local testing without Meta, run `php artisan meta:test-lead --setup` (local/testing environments only). Full guide: [docs/FACEBOOK_INTEGRATION.md](docs/FACEBOOK_INTEGRATION.md).

## Telephony (Exotel)

`/calls` (call history, scoped by `call.view` / `call.view_all`), a **Call** button and Calls tab on each lead, and a softphone widget in the layout (incoming screen-pop, mute / hold / end, required call outcome). Configure in Admin → Integrations → Telephony (`call.configure`): numbers (ExoPhones), one calling account per agent, dispositions, recording storage and retention, health check.

Local development without Exotel: set `TELEPHONY_DRIVER=fake` (only works when `APP_ENV` is `local` or `testing`), enable the integration in Admin → Telephony, create a calling account for your user, and use the simulator panel in the softphone. No real calls are placed.

### Production deployment

1. **HTTPS is required** — for browser microphone access (WebRTC) and for provider callbacks.
2. Set `TELEPHONY_DRIVER=exotel` and `EXOTEL_ACCOUNT_SID`, `EXOTEL_API_KEY`, `EXOTEL_API_TOKEN`, `EXOTEL_SUBDOMAIN`, `EXOTEL_WEBHOOK_SECRET`, `EXOTEL_DEFAULT_CALLER_ID` in `.env` (never in the database, docs or tickets). Browser calling also needs `EXOTEL_WEBRTC_ACCESS_TOKEN` and `EXOTEL_WEBRTC_SDK_URL`. Then run `php artisan config:cache`.
3. Callback URLs in the Exotel call flow: `https://YOUR-CRM/webhooks/telephony/exotel/status?token=<EXOTEL_WEBHOOK_SECRET>` and a Passthru applet pointing to `.../webhooks/telephony/exotel/passthru?token=<secret>`. The admin page shows these URLs without the secret. Optionally restrict source IPs with `EXOTEL_WEBHOOK_ALLOWED_IPS`.
4. Caller ID: add the ExoPhones under Numbers and mark one as the default.
5. Agents: create a calling account per user with a registered phone (click-to-call) and/or an Exotel app user id (browser calling). Agents must allow microphone access for the CRM site.
6. Recording: before enabling it, **review customer consent requirements** (add an announcement in the Exotel flow). Choose storage (`provider` or `private_storage`) and retention (30 days – 3 years) in Admin → Telephony. Recording playback is proxied through the CRM; the provider URL is never exposed.
7. Run the queue worker (the `integrations` queue) and the scheduler (`telephony:reconcile-pending` every 5 minutes, `telephony:prune` daily).
8. The fake driver refuses to start outside local/testing, so a mis-set `TELEPHONY_DRIVER=fake` fails loudly instead of faking calls.

Full guide: [docs/TELEPHONY_MODULE.md](docs/TELEPHONY_MODULE.md). There is no call export and no bulk recording download.

## Reports

`/reports` (Report centre) with Sales overview, Pipeline, Funnel, Lost leads, Lead analytics, Ageing & neglected, Assignments, Salesperson performance, Activity, Response time, Calls, Follow-ups, Meetings and Sources/Campaigns/Meta. Pick a date preset or custom range (CRM timezone) and optional salesperson (admins only), source, campaign, status, priority, city or state filters. Filter state lives in the URL, so a view can be bookmarked. Click a number to open the matching leads, calls, follow-ups or meetings.

- **Scope:** `report.view` = own data ("My", sales users), `report.view_all` = company including unassigned leads ("Company", admins). Every total, chart, filter option and export uses the same scope. Tampered user filters and any team parameter are ignored.
- **Exports:** CSV of one report table, only with `report.export` (Admin by default; **not** Sales Managers or Executives, who get 403 plus an audit entry). Files are private, owner-only and deleted after 24 h (`reports:prune-exports`, hourly). Exports above 2,000 rows are queued and need the queue worker.
- **Dashboard:** a "this month" KPI strip with the same scope. Admins also get an Unassigned leads card.

Metric definitions (cohort vs period wins, first response vs first contact, connection rate, pipeline value, and more): [docs/REPORTING_MODULE.md](docs/REPORTING_MODULE.md).

### Lead value (temporarily hidden)

Lead estimated value functionality is temporarily hidden from the CRM UI and exports. Existing stored values are retained in the database for future reactivation. Lead forms don't show the field or accept it, and Lead 360, the lead list, the pipeline, dashboard KPIs, reports and CSV exports show no value, weighted value or value-based chart. The `leads.estimated_value` column and its data are untouched. To reactivate everything at once, set `CRM_LEAD_VALUE_ENABLED=true` and run `php artisan config:clear`.

## Company branding

Admin → System Settings → General → **Branding** (`settings.manage`): company logo (PNG, JPG or WEBP, up to 2 MB) and favicon (PNG, ICO or WEBP, up to 512 KB). The company name is the existing **Company name** setting. The logo appears on the login screen and in the sidebar, and it's also the browser notification icon. The favicon is used in the browser tab. Files are stored on the dedicated `branding` disk (`storage/app/branding`) under random names and served through `/branding/{logo|favicon}?v=…`, never from a storage path. If there's no upload, the CRM falls back to its initials badge.

Browser titles use the client's name ("Leads · ABC Realty | CRM"). System emails also carry client branding: the logo or company name in the header, and "Regards, ABC Realty". The CRM is provided by **Buildify360**, so a subtle "Powered by Buildify360" credit (11px, muted, linking to https://buildify360.com in a new tab) appears in three places only: at the bottom of the login page, in the sidebar footer under the user profile, and in email footers. The credit comes from `config/crm.php` → `platform` and is deliberately **not** a setting or env value, so client admins can't change or remove it. It never appears beside the client logo, in page headings or in KPI cards. It's hidden in the icon-only sidebar rail, where there's no room for text.

## Browser notifications and sound

Each user can opt in from **My profile → Notification preferences** (or the cog in the notification bell). When the CRM isn't the active tab, or the browser is minimized, or all CRM tabs are closed, they get OS notifications for **new leads assigned to them** (`NEW_LEAD_ASSIGNED`) and **follow-up reminders** (`FOLLOWUP_REMINDER`). In the active tab they get an in-app toast. Each event has its own short synthesised sound, with test buttons and a delayed test notification on the preferences card. This uses real Web Push (service worker + VAPID) and needs **HTTPS in production** (`localhost` is allowed during development). With the tab closed the OS plays its default notification sound, and delivery with the browser fully closed isn't guaranteed.

If notifications don't arrive, run `php artisan notifications:doctor --user=<email>`. It checks the scheduler, reminder claims, queue worker, push configuration, OpenSSL and the user's subscriptions separately.

```powershell
php artisan webpush:vapid     # prints WEBPUSH_VAPID_PUBLIC_KEY / PRIVATE_KEY for .env (never commit the private key)
```

Pushes are sent by the queue worker. On Windows/WAMP, start the queue worker with `OPENSSL_CONF` set (for example `$env:OPENSSL_CONF="C:\wamp64\bin\php\php8.3.6\extras\ssl\openssl.cnf"`), or PHP can't encrypt push messages and every push fails. Admins can switch browser notifications and sounds off globally in System Settings → Notifications. Full guide: [docs/BROWSER_NOTIFICATIONS.md](docs/BROWSER_NOTIFICATIONS.md).

## Production

```bash
composer install --no-dev --optimize-autoloader && npm ci && npm run build   # build machine
php artisan migrate --force && php artisan db:seed --class=ProductionSeeder --force   # fresh DB; system data only
php artisan crm:create-super-admin                                           # interactive; no password flag
php artisan crm:production-data-check                                        # counts only; 1 Super Admin, 0 business rows
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan app:production-check                                             # PASS/WARN/FAIL; exit 1 on any FAIL
```

`GET /health` returns `{"status":"ok"}` (or 503) for uptime monitors. Security headers and a nonce-based Content-Security-Policy are sent by the app (`config/security.php`). The fake telephony driver, demo seeders, `meta:test-lead` and `crm:seed-performance` refuse to run outside local/testing. Full runbook, rollback and go-live checklists: [docs/PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md).

## Tests

```powershell
php artisan test          # PHP feature tests
npm run test:js           # Vitest: notification sounds, de-duplication rules, service worker
```

PHP tests run on in-memory SQLite with core reference data seeded automatically. The JS tests use a fake `AudioContext` and mocked service-worker globals, and need no audio hardware or browser.
