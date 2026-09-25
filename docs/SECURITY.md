# Security

## Controls

| Area | Implementation |
|------|----------------|
| Authentication | Session guard, bcrypt hashing, session regenerated on login, invalidated on logout |
| Registration | Disabled — only admins with `user.create` create accounts |
| Login throttling | 5 attempts per email+IP (Breeze `LoginRequest`), `Lockout` event audited |
| Inactive users | Cannot log in; active sessions terminated by `EnsureUserIsActive` middleware |
| CSRF | Laravel `VerifyCsrfToken` on all web routes (never disabled globally). `/webhooks/meta/leads` is registered outside the `web` group (no session, no CSRF) and is HMAC-verified instead |
| XSS | Vue escapes by default; `v-html` is not used for user content; security headers |
| SQL injection | Eloquent / query builder bindings only; sort columns validated against whitelists |
| Mass assignment | Explicit `$fillable`; FormRequests return validated subsets; ownership fields set by services |
| Authorization | `permission:` middleware + Policies + SQL visibility scopes (defence in depth) |
| Data access model | **Own / All only.** `*.view_all` (Admin / Super Admin) or `leads.assigned_to = me`. No team-based fallback, and unassigned leads are admin-only. Rules are permission-driven, never role-name checks. Legacy `team_id` / `manager_id` / `team_users` data and the deprecated `*.view_team` / `team.*` permissions are ignored server-side (`Permissions::DEPRECATED` is stripped in `PermissionRegistrar`). Reassignment revokes the previous owner's access to the lead and all nested records on the next request. See [CRM_ARCHITECTURE.md §2.4](CRM_ARCHITECTURE.md#24-access-model-own--all-team-visibility-removed) |
| Secrets | `encrypted` casts (APP_KEY), `$hidden` on models, redacted from audit logs |
| Sessions | `SESSION_ENCRYPT=true`, `http_only`, `same_site=lax`, database driver, 120-minute lifetime. `SESSION_SECURE_COOKIE` defaults to `true` when `APP_ENV=production` |
| HTTPS | Required in production (`app:production-check` fails on an `http://` `APP_URL`). URLs are forced to `https` in production; `TRUSTED_PROXIES` lets Laravel see HTTPS behind a TLS-terminating proxy. HSTS (`max-age=31536000; includeSubDomains`) on secure requests. The HTTP→HTTPS redirect is done by the web server (see [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md)) |
| Headers | `SecurityHeaders` middleware: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy: same-origin`, `Permissions-Policy: camera=(), microphone=(self), geolocation=(), payment=(), usb=()` (microphone on the CRM origin only, for Exotel browser calling) |
| Content-Security-Policy | HTML responses only, see [CSP](#content-security-policy) below. Nonce-based `script-src` (no `unsafe-inline` / `unsafe-eval`), no bare `*` in any directive, `object-src 'none'`, `frame-ancestors 'self'`, `base-uri 'self'` |
| CORS | `config/cors.php` has no paths: no CORS headers are ever sent, so browsers block cross-origin reads. Webhooks are server-to-server and need no CORS |
| Debug / errors | `APP_DEBUG=false` in production (release-blocking; checked by `app:production-check`). 403/404/429/500 render the Inertia `Error` page, and 503 renders a static page. If Inertia can't render (for example the database is down), self-contained Blade views in `resources/views/errors` are used. Exception messages, paths and stack traces never reach the browser; JSON clients get `{"message":"Server Error"}` |
| Health | `GET /health` (stateless, no cookies, `throttle:health` 60/min/IP) returns only `ok`/`fail` for app, database and cache. No versions, paths, hostnames, database names or error text. Laravel's default `/up` is not registered |
| Development-only code | Fake telephony provider and simulator routes (404 unless `APP_ENV` is local/testing *and* `TELEPHONY_DRIVER=fake`), `meta:test-lead` and `crm:seed-performance` (refuse outside local/testing), and demo seeders (throw outside local/testing). All are server-side guards |
| Demo data | Demo accounts use a shared password and exist only in local development. **DO NOT USE DEMO CREDENTIALS IN PRODUCTION.** Production seeding creates roles, permissions, settings and reference data only; `app:production-check` fails if any `@salescrm.local` account exists |
| Super Admin bootstrap | `php artisan crm:create-super-admin`: interactive, with the password read from a hidden prompt only (no option, so it never enters shell history). At least 12 characters with mixed case, a number and a symbol; unique email; audited as `USER_CREATED` (route `console`). The password is never printed, logged or emailed |
| Passwords | Policy `Password::defaults()`: minimum length from the `security.password_min_length` setting (default **12** for new installs; existing installs keep their saved value), letters, mixed case and numbers. bcrypt, 12 rounds |
| MFA | Not implemented. Recommended mitigations until the client requests MFA: strong passwords, login throttling and lockout, login history review, deactivating leavers promptly. Can be added later (for example TOTP) without schema changes to existing tables |
| Files | Private disk, streamed through `AttachmentController` after policy check; never public URLs |
| Exports | Only behind `lead.export` / `report.export`; denied attempts return 403 and log `EXPORT_ATTEMPTED` |
| Reports | `ReportScope` = module visibility AND report tier; filter options and exports use the same scope; tampered user ids and any team parameter dropped; no team report (`/reports/teams` 404); unknown slugs 404; results never cached, logged or audited (only report name, filters and row count) |
| Report exports | CSV on the private `local` disk (`report-exports/{uuid}.csv`), owner-only download route with `no-store`, expires after `report.export_retention_hours`, pruned hourly; queued jobs re-check `report.export`; formula-injection guard on text cells; path never serialized; the private disk has no public file route at all (`filesystems.disks.local.serve = false` since Phase 8, so `/storage/*` returns 404) |
| Webhooks | `X-Hub-Signature-256` HMAC-SHA256 over the **raw body** with `hash_equals`; missing or invalid → 403, nothing stored; 2 MB cap; invalid-signature limiter (20/min/IP); plain-text errors; only ids stored in the event ledger (no PII) |
| Meta secrets | App secret and verify token in `.env` only; access tokens `encrypted` + `$hidden`; `SecretRedactor` on logs (`RedactSecrets` tap) and audit values — see [FACEBOOK_INTEGRATION.md §11](FACEBOOK_INTEGRATION.md#11-secrets-policy) |
| Telephony webhooks | Exotel does not sign voice callbacks: secret token in the callback URL (`hash_equals`), optional IP allow-list, `AccountSid` match, status callbacks applied only to known calls; outside the `web` group; payload cap; invalid-attempt limiter; rejections audited as `CALL_WEBHOOK_REJECTED`; payloads sanitised and encrypted; phone numbers and full callbacks not logged |
| Telephony secrets | Exotel key/token, webhook secret and WebRTC token in `.env` only; never in props, HTML, logs, audit or exceptions. Browser sessions are per agent, `no-store`, kept in memory only |
| Call recordings | Provider URL encrypted, never sent to the browser; streamed through `CallRecordingController` after policy check (listen / download are separate permissions); HTTPS host allow-list, no redirects (SSRF guard); listens and downloads audited |
| Call destinations | Server resolves the number from the lead contact field; raw numbers only with `call.manual_dial` |
| Rate limiting | `throttle:login`, `throttle:search`, `throttle:meta-webhook` (1200/min/IP), `throttle:telephony`, `throttle:telephony-webhook` (600/min/IP), `throttle:sensitive`, `throttle:health`, global `throttle:web-actions` on mutations. Webhook caps sit far above provider delivery and retry rates (Meta batches and retries with back-off; Exotel sends a few callbacks per call), so legitimate retries are never throttled; the separate invalid-signature/token limiters only count rejected requests |
| Frontend payloads | Shared props expose only safe user fields (`id`, `name`, `email`, `role`, `permissions`) |
| Branding uploads | `settings.manage` only; MIME sniffed server-side (`mimetypes:`). Logo accepts PNG/JPEG/WEBP (2 MB), favicon PNG/ICO/WEBP (512 KB). SVG is rejected because there is no sanitiser. Files go to a dedicated `branding` disk (`storage/app/branding`), separate from attachments and recordings, under random 40-character names with the extension derived from the sniffed type. The new file is stored before the old one is deleted. Served by `BrandingController` with `nosniff` and `Content-Security-Policy: default-src 'none'`. URLs carry only a content-hash version (`/branding/logo?v=…`), never a storage path. Audit records file name, MIME type and size only |
| Browser push | Real Web Push (VAPID). The VAPID private key lives in `.env` only; just the public key reaches the browser. Subscription endpoint and keys are `encrypted` and `$hidden`. Subscribe/unsubscribe are authenticated, CSRF-protected, `throttle:sensitive`, and always bound to the session user. Endpoints must be HTTPS on an allow-listed push service (SSRF guard). Payloads carry a title, a short body (name only, no phone, email or amounts, and names can be hidden for lock screens), the notification id and a relative URL. Clicks go through `/notifications/{id}/open`, which re-checks ownership and visibility. Logs contain user, notification or subscription ids and HTTP status only, never endpoints, keys or tokens. Logout removes that browser's subscription. See [BROWSER_NOTIFICATIONS.md](BROWSER_NOTIFICATIONS.md) |
| Lead value | Hidden from UI, props and CSV exports by `crm.features.lead_value` (Phase 7.1). The value isn't accepted from forms, and stored data is retained |

## Audit redaction

`AuditService` recursively replaces values whose key contains any of:
`password`, `token`, `secret`, `api_key`, `authorization`, `remember`, `private_key`, `cookie`
with `[REDACTED]`. Since Phase 5, values also pass through `App\Support\SecretRedactor`, which masks tokens, `appsecret_proof`, the OAuth `code` / `state`, Bearer headers and `sha256=` signatures inside strings.

## Content-Security-Policy

Sent by `SecurityHeaders` on HTML responses (not JSON, not while the Vite dev server runs; responses that already set a CSP, such as branding files, keep theirs). Allowed origins live in `config/security.php`:

| Directive | Sources | Why |
|-----------|---------|-----|
| `default-src` | `'self'` | |
| `script-src` | `'self'`, per-request nonce, Exotel SDK origin (from `EXOTEL_WEBRTC_SDK_URL`, https only), `CSP_EXTRA_SCRIPT_SRC` | Vue/Vite bundles; nonce on Ziggy `@routes` and Vite prefetch; lazy-loaded Exotel CRM Web SDK |
| `style-src` | `'self'`, `'unsafe-inline'`, `https://fonts.bunny.net` | Inter font CSS; Inertia progress bar and Vue style bindings inject inline styles |
| `font-src` | `'self'`, `data:`, `https://fonts.bunny.net` | Inter font files |
| `img-src` | `'self'`, `data:`, `blob:`, `https://*.fbcdn.net`, `https://*.fbsbx.com` | Branding previews; Facebook Page pictures in Admin → Facebook |
| `media-src` | `'self'`, `blob:`, Exotel hosts | Recordings are streamed through the CRM; SDK audio |
| `connect-src` | `'self'`, `https://*.exotel.com`, `wss://*.exotel.com`, `https://*.exotel.in`, `wss://*.exotel.in`, `CSP_EXTRA_CONNECT_SRC` | Inertia XHR; Exotel SDK signalling |
| `worker-src` / `manifest-src` / `frame-src` | `'self'` | Push service worker |
| `form-action` | `'self'`, `https://www.facebook.com` | Meta OAuth redirect after the Connect form |
| `frame-ancestors` / `base-uri` | `'self'` | Clickjacking / base-tag injection |
| `object-src` | `'none'` | |

`upgrade-insecure-requests` is added on HTTPS. The exact hosts contacted by the Exotel SDK depend on the SDK version enabled on the client's account: verify browser calling on staging with `CSP_REPORT_ONLY=true` and the browser console open, then add any reported host to `CSP_EXTRA_CONNECT_SRC` (never `*`). `CSP_ENABLED=false` is an emergency switch only.

## Known considerations

- **Ziggy route list**: route *names/URIs* are exposed to the browser. This reveals no data; every route is permission-protected. It can be restricted via `config/ziggy.php` groups if required.
- **Queue workers** run as the system user: audit entries from jobs record `user_id = null` and `route = queue:<JobClass>`.

## Hardening checklist (Phase 7)

- [x] Sales Executive A → `GET /leads/{B's lead}` → 403/404, including notes, attachments, enquiries, follow-ups, meetings, calls, recordings and nested writes (`AccessModelTest` §38)
- [x] Unassigned leads visible to `lead.view_all` only; reassignment revokes access to all nested records (`AccessModelTest` §39–40)
- [x] Legacy managers and legacy `team_id` / `*.view_team` grants give no access; `/admin/teams` and `/reports/teams` → 404 (`AccessModelTest` §41–43)
- [ ] Sales Executive → `GET /leads/export` → 403 + audit
- [x] Sales Executive → `POST /reports/*/export` → 403 + `EXPORT_ATTEMPTED`; Sales Manager 403 by default (Phase 7 release check E)
- [x] Reports, filter options, drill-downs and exports never exceed the viewer's scope (Phase 7 release checks A–D, F)
- [x] Export files private, owner-only, expiring; no report data or PII in audit, log or cache (Phase 7 release checks G, P)
- [x] Sales Executive sends `assigned_to`, `created_by`, `team_id` → ignored (`team_id` is never written on new records; lead creation / user management tests)
- [x] Sales Executive → any `/admin/*` → 403 (Phase 8 `RoleSecurityRegressionTest`)
- [x] Attachments of foreign leads → 403 (`AccessModelTest` §38, `LeadAttachmentTest`)
- [x] Search/autocomplete and duplicate check never return foreign leads (`AccessModelTest` §38)
- [x] Webhook with bad or missing signature → 403, nothing stored, rejection logged and audited as `FACEBOOK_WEBHOOK_REJECTED` (Phase 5 tests)
- [ ] Rate limits trigger 429
- [ ] Audit log update/delete impossible via model
- [x] No secret appears in any Inertia response, log or audit row (Phase 5 release check E)
- [x] Telephony callback without the secret token → 403, nothing applied, audited (Phase 6 tests)
- [x] Recording URL never reaches the browser; Sales Executive cannot listen or download (Phase 6 release checks E–G)
- [x] Fake telephony provider cannot run in production (Phase 6 tests)
- [ ] Recording consent announcement configured in the Exotel flow before enabling recording (business / legal review)
- [x] Sales Executive / Manager → `POST|DELETE /admin/branding/*` → 403; SVG, disguised PHP/HTML and oversize uploads rejected (Phase 7.1 tests)
- [x] Users cannot create or remove another user's push subscription; non-allow-listed endpoints rejected (Phase 7.1 tests)
- [x] Lead value absent from lead props, reports and CSV exports while the flag is off (Phase 7.1 tests)
- [ ] Production: HTTPS enabled and VAPID private key set only in the server `.env` (browser push)

## Production hardening (Phase 8)

Verified by automated tests (`tests/Feature/Phase8`):

- [x] `app:production-check` fails on debug, non-https URL, `sync` queue, `log` mailer, fake telephony, insecure cookies, demo accounts; never prints secret values (`ProductionGuardTest`, `SensitiveConfigExposureTest`)
- [x] Demo seeders throw outside local/testing; production seeding creates system data only and no users (`DemoSeederProductionBlockTest`)
- [x] `ProductionSeeder` is idempotent and creates no users or business records; `crm:clear-demo-data` refuses production without `--force-production` plus the typed phrase; `crm:create-super-admin` never creates a second Super Admin; `crm:reset-super-admin` never prints or logs the password; `crm:production-data-check` prints counts only; empty dashboard, reports, pipeline and calendar render (`ProductionBootstrapTest`)
- [x] Fake telephony and its simulator routes unavailable in production/staging (`FakeTelephonyProductionBlockTest`)
- [x] No exception detail reaches browsers or JSON clients with `APP_DEBUG=false`; self-contained error pages (`DebugConfigurationTest`)
- [x] Private disk not publicly served; attachment downloads require login, permission and visibility (`PrivateFileAccessTest`)
- [x] Report exports expire and are pruned hourly (`ReportExportExpiryTest`)
- [x] `/health` minimal, stateless, rate limited, no internals (`HealthEndpointTest`)
- [x] Role boundaries unchanged (`RoleSecurityRegressionTest`)
- [x] No debug/test routes; every route controller-based (route cache) and authenticated except documented public endpoints (`ProductionRoutesTest`)
- [x] CSP with nonce and no wildcard sources; no CORS grants; secure-cookie default (`SecurityHeadersTest`)

Must be verified on the client's staging/production infrastructure (cannot be verified locally):

- [ ] HTTPS certificate valid, HTTP redirects to HTTPS, HSTS header present
- [ ] `php artisan app:production-check` passes on the server
- [ ] Browser calling works with the CSP enforced (SDK version on the client's Exotel account) — use `CSP_REPORT_ONLY=true` on staging first and add any blocked Exotel host to `CSP_EXTRA_CONNECT_SRC`
- [ ] Meta OAuth connect completes with the CSP enforced
- [ ] Recording consent announcement configured in Exotel (legal review)
