# Reporting Module (Phase 7)

Factual, permission-scoped reporting on leads, pipeline, conversion, response speed, calls, follow-ups, meetings, campaigns, assignments and activity. Reports are **read-only**: the only write is the creation of an export file. Reports never change a lead, assignment, call, follow-up or meeting. To act on a number, follow its drill-down to the operational screen.

There is no AI scoring, win probability, salesperson score, "best/worst performer" label, scheduled email or dashboard builder. The "weighted pipeline" is the status probability an admin typed in, not a prediction.

> **Lead value hidden (Phase 7.1).** Lead estimated value functionality is temporarily hidden from the CRM UI and exports. Existing stored values are retained in the database for future reactivation. While `crm.features.lead_value` (`CRM_LEAD_VALUE_ENABLED`, default `false`) is off, `App\Support\LeadValue` strips every value metric in one place, after the definitions compute their sections:
>
> - KPIs with format `currency`, for example Won value, Lost value, Open pipeline value, Estimated/Weighted/Average deal value, and the dashboard's Won value and Open pipeline;
> - value-based charts (for example "Estimated value by status");
> - table columns with format `currency`, plus the value-only columns `no_value` ("Without value") and `probability` ("Status probability"), including their row and total cells.
>
> Screens and CSV exports go through the same filter, so an export can never contain a value the screen hides. The definitions and SQL below are unchanged and come back as they were when the flag is turned on. The value metrics in the dictionary (§7) are kept for that reason.

## 1. Architecture

```
ReportController ──► ReportService::page(user, slug, input)
                        ├─ ReportScope::for(user)        tier OWN | ALL (else 403)
                        ├─ ReportFilters::fromInput()    preset / range / filters, out-of-scope ids dropped
                        ├─ ReportQueries(scope, filters) scoped base queries (leads, cohort, calls, …)
                        ├─ ReportRegistry::resolve(slug) whitelist → ReportDefinition (404 otherwise)
                        └─ definition->sections(q, links) KPI tiles, charts, tables (all numbers computed in PHP/SQL)
Metrics\*Metrics        reusable SQL aggregates (LeadMetrics, ConversionMetrics, ResponseMetrics, CallMetrics,
                        FollowupMetrics, MeetingMetrics, PipelineMetrics, AssignmentMetrics, ActivityMetrics)
ReportExportService     CSV exports of any report table (same definition → CSV always matches the screen)
```

| Layer | Files |
|---|---|
| Routes | `routes/reports.php` |
| Controllers | `App\Http\Controllers\Reports\ReportController`, `ReportExportController` |
| Services | `App\Services\Reports\*` (scope, filters, queries, SQL helpers, lookups, links, registry, service, exports) |
| Definitions | `App\Services\Reports\Definitions\*Report` (one class per report page) |
| Job / command | `App\Jobs\GenerateReportExport`, `reports:prune-exports` (hourly) |
| Frontend | `Pages/Reports/Index.vue`, `Pages/Reports/Show.vue`, `Components/reports/*`, `utils/reportFormat.js` |

The Vue side is a generic renderer: it formats numbers (`en-IN`, currency and durations) and draws what the server sends. **No business metric is calculated in Vue**, and no raw lead list is sent for client-side filtering. Payloads contain aggregates plus the few fields a table shows (for example, the neglected-lead list shows lead number, name, owner, status and age, and is limited to 50 rows).

Chart.js is loaded lazily (a separate ~70 KB gzipped chunk, imported only on report pages) with animation disabled. Colours are deterministic: Won is green, Lost is red, status charts use each status's configured colour, and other series use a fixed palette by position.

## 2. Reports and routes

| Route | Report | Category | Notes |
|---|---|---|---|
| `GET /reports` | Report centre | | Lists the reports available to the viewer |
| `/reports/overview` | Sales overview | Sales | Headline KPIs, previous-period comparison |
| `/reports/pipeline` | Pipeline | Sales | Open pipeline now, by status and by owner |
| `/reports/funnel` | Funnel & conversion | Sales | Cohort funnel, stage durations, time to win and loss |
| `/reports/lost-leads` | Lost leads | Sales | Reasons, stage lost at, time to loss |
| `/reports/leads` | Lead analytics | Leads | Volume, trend, source, status, priority, city, duplicates |
| `/reports/ageing` | Ageing & neglected | Leads | Age buckets, time in status, neglected leads |
| `/reports/assignments` | Assignments | Leads | First assignment, reassignments, time to assignment |
| `/reports/sales-performance` | Salesperson performance | People | Factual metrics side by side, no ranking. ALL: every salesperson + salesperson filter; OWN: own row only |
| ~~`/reports/teams`~~ | *(removed)* | | Team performance was removed with team visibility; the slug returns **404** for everyone, including exports |
| `/reports/activity` | Activity | People | Leads created, status changes, notes, calls, follow-ups, meetings per user |
| `/reports/response-time` | Response time | Activity | First attempt and first contact, Meta speed-to-lead |
| `/reports/calls` | Calls | Activity | Requires call visibility |
| `/reports/follow-ups` | Follow-ups | Activity | Requires follow-up visibility |
| `/reports/meetings` | Meetings | Activity | Requires meeting visibility |
| `/reports/campaigns` | Sources, campaigns & Meta | Marketing | Includes Meta enquiries vs unique leads |
| `POST /reports/{report}/export` | Export one table | | `report.export`, throttled to 10/min |
| `GET /reports/report-exports/{uuid}` | Download an export | | Owner only, while not expired |

Unknown slugs, and reports the viewer's tier cannot use, return **404**. The dashboard also shows a compact "this month" KPI strip (`ReportService::dashboard`) using the same scope, and it is not audited.

## 3. Visibility

| Permission | Tier | Data |
|---|---|---|
| `report.view_all` | ALL ("Company") | Company data the user's module permissions allow, including unassigned leads |
| `report.view` | OWN ("My") | Only the user's own records |
| none | | 403 on `/reports*`; no dashboard KPI strip |

There is no team tier. `report.view_team` is deprecated: it's stripped from resolved permissions, so a legacy grant gives OWN.

`ReportScope` applies **both** layers:

1. The existing module visibility services: `LeadVisibility`, `CallVisibility`, `FollowupVisibility` and `MeetingVisibility`. Calls, follow-ups and meetings still AND-s the linked lead's visibility.
2. For OWN, a cap on the owner column: leads `assigned_to`, calls `agent_user_id`, follow-ups `assigned_to`, meetings `host_user_id`. A user granted `lead.view_all` without `report.view_all` still sees only their own numbers in reports. `team_id` columns are never used.

The Salesperson performance report shows every salesperson for ALL (plus the salesperson filter). An OWN user sees only their own row.

Reports are never built on unrestricted models and filtered afterwards. Every base query starts from the scope.

**Filter privacy.** The salesperson dropdown exists only for ALL. OWN users get no people list. There is no team filter. City and state options are distinct values from leads the viewer can see (at most 200). Source, campaign and status options are reference data. User ids outside the scope and any `team` parameter are **silently dropped** from the request, so a tampered URL shows the viewer's normal scope.

**Drill-down security.** Links go to existing screens (`/leads`, `/calls`, `/follow-ups`, `/meetings`, `/leads/{id}`) with filters in the query string. Those screens apply their own policies, so a drill-down can never show more than the operational list would. A link is omitted when the viewer cannot `viewAny` the target module. The operational lists exclude archived leads, so a drill-down count can be lower than an "archived included" report number.

## 4. Filters and date semantics

- **Presets:** today, yesterday, last 7 days, last 30 days (default), this month, last month, this quarter, last quarter, this year, custom. Custom ranges must be valid dates, with end ≥ start and at most 731 days; otherwise the default preset is used.
- **Timezone:** every range is interpreted in the CRM timezone (`general.timezone`, default Asia/Kolkata). "Today" is 00:00–23:59:59 local time, converted to UTC for queries. Daily, weekly and monthly buckets group by local date (`ReportSql::bucket`), never by raw UTC date. Granularity is daily up to 62 days, weekly up to 200 days, then monthly.
- **DST:** the offset is taken at the end of the period. For timezones with DST, buckets near a transition can be off by one hour. India has no DST.
- **Comparison:** "this month/quarter/year" compare with the same number of days at the start of the previous month/quarter/year (month-to-date vs same days last month). "Last month/quarter" compare with the calendar period before. Other presets compare with the equal-length window immediately before. A change is shown as N/A when the previous value is 0 or null. Percent KPIs show the change in percentage **points**.
- **"Now" snapshots** ignore the date range and are labelled "(now)": open pipeline, overdue follow-ups, upcoming meetings, ageing and neglected leads.
- **Archived leads** are included by default ("archived: include"), because they are part of history. "Exclude" removes them from lead metrics and from calls, follow-ups and meetings linked to them.
- Other filters: salesperson (ALL only), source, campaign, status, priority, city and state. There is no team filter. Each report hides the filters that do not apply to it.

## 5. Cohort vs period metrics

This distinction appears on every report that mentions wins.

| Kind | Question | Population | Timestamp |
|---|---|---|---|
| **Cohort (acquisition)** | "Of the leads we got in September, how many are won today?" | Leads with `leads.created_at` in the period | Status **now** |
| **Period (outcome)** | "How many deals did we win in September?" | Status changes in `lead_status_changes` with `changed_at` in the period | Time of the change |

A lead created on 20 Aug and won on 5 Sep belongs to **August's cohort** (cohort conversion 100% for August) and is **September's win** (Leads won = 1 in September). This is covered by the test *"M: a lead created last month and won this month…"*.

Period outcomes count each lead once per period even if it moved to won twice. A lead lost and later reopened still counts as lost in the period of the loss, but is open "now".

## 6. Status history

`lead_status_changes` is written by `LeadService` for every creation (initial status) and every status change: from, to, `changed_at`, `changed_by`, and the owner at that moment (the legacy `team_id` column is no longer written). On migration it was **backfilled** (`is_backfilled = 1`) from the activity timeline, which has recorded every status change since Phase 2. The initial row uses `leads.created_at`.

- Imports or direct database edits that bypassed `LeadService` have no history. For those leads the funnel falls back to the current (non-lost) status, and stage durations are unavailable. Reports say "Stage timing is available only from status history" rather than inventing values.
- Historical values are never fabricated from the current state.

## 7. Metric dictionary

"Scope" always means the viewer's report scope plus the selected filters. Won value, Pipeline value and Weighted pipeline are currently hidden (see the Phase 7.1 note at the top).

| Metric | Definition | Numerator | Denominator | Timestamp | Example |
|---|---|---|---|---|---|
| **New leads** | Leads created in the period | count of leads | | `leads.created_at` | 40 leads created in Sept |
| **Leads won** | Leads moved to a won status in the period | distinct leads with a to-won change | | `lead_status_changes.changed_at` | 6 won in Sept, whenever created |
| **Won value** | Sum of `estimated_value` of leads won in the period | Σ estimated_value | | `changed_at` of the win | ₹30,40,000 |
| **Leads lost** | Leads moved to a lost status in the period | distinct leads | | `changed_at` | 3 |
| **Win rate** (period) | Share of outcomes that were wins | won in period | won + lost in period | `changed_at` | 6 / (6+3) = 66.7% |
| **Conversion rate** (cohort) | Share of the period's new leads that are won **now** | cohort leads currently won | cohort leads | `created_at` + current status | 4 of 40 = 10% |
| **Qualified** | Cohort leads that reached the configured qualified stage (`report.qualified_status`, default *Interested*) or beyond | cohort leads reaching it | cohort leads | status history | 13 / 33 = 39.4% |
| **Funnel "reached"** | A lead reached stage S if its highest non-lost stage (history or current) has sort order ≥ S | | previous stage / first stage | status history | New 4 → Contacted 3 → … |
| **Stage duration** | Time from entering a status to leaving it, for exits within the period (avg, median, p75, p90) | | | `changed_at` pairs (window function) | Contacted: median 2d 4h |
| **Time to win / loss** | Lead creation → won / lost change, for outcomes in the period | | | `created_at` → `changed_at` | median 12 days |
| **Pipeline value** | Sum of `estimated_value` of open leads **now**. Leads without a value are counted separately ("N open leads have no value") and never guessed | Σ estimated_value | | now | ₹87,10,000 (6 without value) |
| **Weighted pipeline** | Σ estimated_value × status probability ÷ 100 (admin-configured probability, not a prediction) | | | now | New 10%, Negotiation 75% |
| **Open lead** | Not won, not lost, not archived | | | now | |
| **First response time** (first attempt) | Lead creation → earliest outreach of any result: outbound call `started_at`, any completed follow-up, or the first contact if earlier | | | earliest of those | Created 10:00, first dial 10:04 → **4 min** |
| **First contact time** | Lead creation → earliest actual contact: connected call `answered_at`, follow-up completed with a contact outcome (connected, interested, not interested, call back later, demo/proposal required), meeting completed with a contact outcome | | | earliest of those | Connected at 10:12 → **12 min** |
| **Within target** | Cohort leads whose first attempt ≤ `report.response_target_minutes` (default 15) | leads within target | cohort leads | | 54.5% |
| **No attempt** | Cohort leads with no attempt yet (not 0 minutes) | | | | |
| **Calls** | Calls started in the period (inbound + outbound) | count | | `calls.started_at` | 3 |
| **Connected** | Status `answered` or `completed` | count | | `started_at` | 2 |
| **Connection rate** | Connected outbound ÷ finished outbound. Finished = answered, completed, busy, no_answer, failed, missed; in-flight and cancelled calls are excluded | connected outbound | finished outbound | `started_at` | 2 / 3 = 66.7% |
| **Talk time** | Provider-reported `talk_duration_seconds` of connected calls only (busy, no-answer and failed add nothing; ring time is excluded) | Σ talk seconds | | `started_at` | 300 + 600 = 900 s |
| **Average talk time** | Talk time ÷ connected calls with a reported duration | talk seconds | connected calls with duration | | 450 s |
| **Missing disposition** | Calls that require an outcome and have none | | | | |
| **Follow-ups due** | Follow-ups scheduled from the period start to min(period end, now), **excluding** originals replaced by a reschedule (the replacement is counted instead) | | | `scheduled_at` | |
| **Follow-up completion rate** | Completed ÷ due | completed (of due) | due | `scheduled_at` | 1 / 2 = 50% |
| **On-time rate** | Completed within `followup.overdue_alert_after_minutes` of the scheduled time ÷ completed | | | `completed_at` − `scheduled_at` | |
| **Overdue follow-ups (now)** | Pending and `scheduled_at < now`. Same dynamic rule as the Follow-ups module; nothing is stored as "overdue" | count | | now | |
| **Meetings scheduled** | Meetings starting in the period, excluding originals with status `rescheduled` (the replacement meeting counts) | | | `meetings.start_at` | |
| **Meeting completion rate** | Completed ÷ (completed + cancelled + no-show) | completed | closed meetings | `start_at` | 1 / 3 = 33.3% |
| **No-show rate** | No-show ÷ (completed + no-show) | | | `start_at` | 50% |
| **Upcoming meetings (now)** | Scheduled or confirmed meetings with `start_at ≥ now`. A rescheduled original never counts | | | now | |
| **Awaiting update** | Open meetings whose end time has passed without an outcome | | | `end_at` | |
| **Meta enquiries** | Meta lead-form submissions received in the period (one `lead_enquiries` row per submission, channel `facebook`) | enquiries | | `received_at` | 2 |
| **Unique leads** (Meta) | Distinct CRM leads those submissions belong to | distinct lead_id | | `received_at` | 1 |
| **Repeat enquiries** | Enquiries − unique leads | | | | 1 |
| **Meta ingestion delay** | Meta `created_time` (webhook event) → CRM lead `created_at` | | | | |
| **Time to assignment** | Lead creation → first assignment to a user | | | `lead_assignments.created_at` | |
| **Reassignments** | Assignment rows moving a lead from one user to another in the period | | | `lead_assignments.created_at` | |
| **Lead age** | Whole days since `created_at` for open leads, in buckets 0–1, 2–3, 4–7, 8–15, 16–30, 31+ | | | now | |
| **Time in status** | Days since the last status change (or creation) for open leads | | | now | |
| **Neglected lead** | Open lead matching any of: *untouched* (older than `report.untouched_new_lead_hours`, default 4, with no attempt), *overdue* (has an overdue follow-up), *no next action* (no pending future follow-up and no open future meeting), *inactive* (`last_contacted_at`, or created_at, older than `report.inactive_days`, default 7). Derived at query time; nothing is flagged on the lead | | | now | |

Percentages are rounded to one decimal place and are `null` (shown as **N/A**) when the denominator is 0. Durations are stored in seconds, shown as "2h 18m" and exported in minutes.

**Percentiles** (median, p75, p90) use the nearest-rank method on at most 50,000 values per metric. Larger populations fall back to the first 50,000 in index order, which is acceptable for the current data volumes.

## 8. Attribution

- **Lead metrics** (new leads, wins, pipeline, conversion, response time) use the lead's **current** owner. Unassigned leads appear only in Company (ALL) reports. `lead_status_changes` also stores the owner at the time of each change for future "owner at the time" analysis.
- **Calls** use the agent on the call (`agent_user_id`, historical and never rewritten).
- **Follow-ups** use the assignee, and `completed_by` for the activity report.
- **Meetings** use the host.
- No report reads the deprecated `team_id` columns.
- Activity (outreach) counts whoever performed it.
- Inactive and deleted users stay in historical rows as "(inactive)" and are hidden when they have no activity in the period.

## 9. Exports

- **Permission:** `report.export` **plus** a report tier. **Sales Executives: no export**; the backend returns **403** and audits `EXPORT_ATTEMPTED`, whatever the UI shows. Managers do **not** receive export by default; grant it per user or role. Admin holds it by default, and Super Admin bypasses.
- **Content:** exactly one table of the report, built by the same definition with the same scope and filters, so it can never contain rows the viewer cannot see. Detail lists (neglected leads) stream every matching row with `lazyById`.
- **Format:** CSV with a UTF-8 BOM (Excel-friendly). Text cells starting with `= + - @` or a tab are prefixed with `'` (formula-injection guard). Durations are exported in minutes, and currency and percent columns are labelled in the header.
- **Size:** at or below `report.export_queue_threshold` (default 2,000 rows, minimum 100) the file is generated immediately and the page receives a one-time download link. Larger exports are **queued** (`GenerateReportExport`, `default` queue) and appear under "My exports" when ready (the page polls every 5 s while pending). The job re-checks that the user is active and still holds `report.export`; otherwise the export fails and no file is written.
- **Storage:** private `local` disk under `storage/app/private/report-exports/{uuid}.csv`, with no public URL. Download goes through `GET /reports/report-exports/{uuid}`: owner only, `report.export` still required, `Cache-Control: no-store`. Files expire after `report.export_retention_hours` (default 24). `reports:prune-exports` (hourly) deletes expired files and keeps the metadata row (status `expired`) for the audit trail. The `path` and `disk` columns are never serialized.
- **Audit:** `REPORT_EXPORTED` records report, section, format, filters and row count, and `REPORT_EXPORT_DOWNLOADED` records report, section and row count. **Never the data.**

## 10. Audit and logging

- `REPORT_VIEWED`: at most one entry per user, report and 10 minutes (`Cache::add` on `report-viewed:{user}:{slug}`), with the report slug and filter values only. Filter changes and chart refreshes do not create more entries.
- Report results, lead names, phone numbers and email addresses are never written to the audit log, the application log or the cache. Failed exports log the export id, report and exception class only.

## 11. Caching

Report **results are not cached**. Every page is computed live from the viewer's scope, so cached admin data can never be served to a sales user. The only cache entries are the per-user view-audit throttle keys (no data).

## 12. Performance

- Every KPI group, chart and table is one aggregate query. There is no per-row or per-user query loop (N+1): user and status names are resolved from small cached-per-request lookups. Query counts are **constant in the number of leads**. The test suite checks 25 vs 100 leads for every report and 30 vs 1,030 leads for the overview. On the demo data, pages run 11–58 queries in 25–240 ms.
- Indexes used (all pre-existing except the Phase 7 ones): `leads (created_at)` plus the owner/status foreign-key indexes (the legacy `team_id` indexes still exist but are no longer used by reports); `lead_status_changes (lead_id, changed_at)`, `(to_status_id, changed_at)` and `(changed_at)` (Phase 7); `lead_assignments (created_at)` (Phase 7); `calls (agent_user_id, started_at)`, `(team_id, started_at)`, `(lead_id, started_at)`, `(status, started_at)`; `followups (assigned_to, status, scheduled_at)`, `(team_id, status, scheduled_at)`, `(lead_id, status, scheduled_at)`; `meetings (host_user_id, status, start_at)`, `(team_id, status, start_at)`, `(lead_id, status, start_at)`; `lead_enquiries (lead_id, received_at)`. On the demo dataset MySQL scans the tiny tables directly; the period and owner indexes take over as volumes grow.
- Response time uses correlated `MIN()` subqueries per cohort lead, bounded by the cohort size (one period of leads).
- SQL is driver-aware (`ReportSql`): MySQL `TIMESTAMPDIFF`/`DATE_ADD`/`LEAST` vs SQLite `strftime`/`MIN` for the test suite.

## 13. Settings (Admin → Settings → Reports)

| Key | Default | Meaning |
|---|---|---|
| `report.qualified_status` | `interested` | Status that counts as "qualified" in the funnel |
| `report.response_target_minutes` | 15 | First-response target (5, 15, 30, 60, 240, 1440) |
| `report.untouched_new_lead_hours` | 4 | Hours before an un-attempted lead counts as neglected |
| `report.inactive_days` | 7 | Days without contact before an open lead is "inactive" |
| `report.export_queue_threshold` | 2000 | Rows above which exports are queued |
| `report.export_retention_hours` | 24 | Export file lifetime (1, 6, 24, 72, 168) |

## 14. Demo data (local only)

`ReportingDemoSeeder`, called from `DemoSeeder` and **refusing to run outside `APP_ENV=local`**, adds:

- executive Arjun Mehta (`arjun@salescrm.local` / `Password@123`) with five leads. He's placed on a legacy "Mumbai Team" row; the demo seeders deliberately stamp legacy `team_id` values to show they grant nothing;
- historical calls with every outcome (connected, no answer, busy, failed, one missed inbound);
- a repeat Meta enquiry (one lead, two enquiries);
- a no-show meeting.

It also spreads the demo status history over each lead's life. It skips itself once demo calls exist. Production seeding never creates analytics data.

## 15. Tests

`tests/Feature/Reports/`:

| File | Covers |
|---|---|
| `ReportVisibilityTest` | Own/all (legacy team grants → own), tampering, filter options, drill-downs, dashboard, `/reports/teams` 404 |
| `ReportExportTest` | 403, grants, isolation, private/expiring files, queue, formula guard |
| `ReportMetricsTest` | First response, calls, follow-ups, meetings, cohort vs period, funnel, pipeline, ageing, archived, N/A comparisons, read-only |
| `ReportMetaAndTimezoneTest` | Meta enquiries, 23:59/00:00, daily buckets, calls across midnight |
| `ReportQueryAndAuditTest` | Query counts, REPORT_VIEWED throttling, no PII in audit/log/cache, demo seeder guard |

Release checks A–P in the Phase 7 brief map to tests named `A: …` to `N: …`, `O: …` and `P: …`.
