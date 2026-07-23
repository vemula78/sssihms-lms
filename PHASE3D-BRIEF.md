# Phase 3d — KPI Dashboard v2 (independent build brief)

You're building on the SSSIHMS LMS, a WordPress plugin (courses, quizzes, checklists,
hours, journals, documents, certificates — all Phase 1, already built and live). This
brief is self-contained; you don't need prior conversation history.

## Read first, in order
1. `SPEC.md` — functional spec (F11 = existing analytics dashboard, your starting point)
2. `ARCHITECTURE.md` — data model, ADRs
3. `CONVENTIONS.md` — mandatory registration pattern, security rules, style
4. `ROADMAP-PHASE3.md` — section "Phase 3d — KPI dashboard v2" is your scope
5. `admin/class-sslms-admin-dashboard.php` — the existing dashboard you're extending

## Scope (build exactly this, nothing more)

1. **Department/cohort dimension**: add a `department` filter usable everywhere the
   existing dashboard shows a metric, sourced from `sslms_profiles.discipline` (already
   exists — no schema change). Every existing KPI card and chart gets an optional
   department filter dropdown.
2. **New KPI cards**: compliance % (valid credential documents / total required),
   overdue sign-offs with ageing (days overdue), overdue hour approvals with ageing,
   OSPE pass rate (skip if Phase 3c/OSPE tables don't exist yet — guard with
   `class_exists`/table-exists checks, degrade gracefully), expiring-credential
   forecast at 30/60/90 days (three numbers, not just one).
3. **Threshold alerts**: an options-based config (reuse `add_option`/`update_option`,
   no new table) letting `sslms_manage` set a red-line per KPI (e.g. "alert if
   compliance % < 90"). Breached thresholds render as a visually distinct alert
   banner on the dashboard AND fold into the existing admin digest email
   (`SSLMS_Documents::run_expiry_check` pattern — hook your own cron or extend
   the existing daily cron event, don't duplicate a second cron).
4. **Self-refresh**: the dashboard page polls its own data every 30–60s via a small
   REST endpoint (`sslms/v1` namespace, follow `SSLMS_REST_Base` pattern exactly) and
   re-renders the numbers/charts without a full page reload. No websockets.
5. **Board/TV mode**: a `?sslms_tv=1` query param on the dashboard renders a
   full-screen, larger-type, auto-rotating (every ~15s) view cycling through
   department KPIs. No login-free access — still requires `sslms_view_reports`.

## Non-negotiables (from CONVENTIONS.md, restated because they matter)
- Do NOT modify the DB schema. Add tables only if section 2 truly requires them
  (aggregation can likely be done with prepared SQL against existing tables) — if you
  believe a new table is unavoidable, stop and note it in your final report instead of
  creating it.
- Every SQL query: `$wpdb->prepare()`. Every output: escaped. Every REST route:
  capability check (`sslms_view_reports` minimum; department-unscoped views need
  `sslms_manage`) AND instructor-vs-manage scoping identical to the existing dashboard.
- No new JS framework. Vanilla JS + the existing `assets/js/sslms.js` `SSLMS.api()`
  helper for polling. Chart.js v4 UMD is already bundled — reuse it, don't add a
  second charting library.
- `php -l` clean on every file you touch or create.
- Do not touch any file outside `admin/class-sslms-admin-dashboard.php`,
  `includes/rest/` (new file for the polling endpoint), and `assets/js/` (new file if
  needed, prefixed `sslms-dashboard-`). If you need a shared helper that doesn't exist,
  add it to `includes/modules/` as a new file — don't edit existing module files
  built by other work in progress.

## When done
Report: files created/changed, exact KPI SQL for each new card (so it can be
independently verified), any scope you deliberately skipped and why, `php -l` status,
and a functional test you ran (e.g. via `wp eval` against local Docker or the demo
site if you have VM access) showing the dashboard renders with real data.

Do not commit or push — leave changes in the working tree for review.
