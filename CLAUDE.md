# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

SSSIHMS LMS — a WordPress plugin (`sssihms-lms/`) implementing a Learning Management
System for Sri Sathya Sai Institute of Higher Medical Sciences, Whitefield, a charitable
hospital in India. Three education tracks share one system: clinical (nursing/allied
health compliance + competency), allied-health practical evaluation, and chaplaincy
formation. `SPEC.md` is the functional specification and the anchor for all verification
work — read it (and `ARCHITECTURE.md` for the data model/ADRs) before making non-trivial
changes.

Stack: PHP 8 / WordPress plugin, custom MySQL tables (not post-meta), vanilla JS,
Chart.js v4 (bundled, no CDN). Chosen over Laravel/Node because the hospital's small IT
team already runs WordPress and maintains a sibling plugin in the same shape — see
`ARCHITECTURE.md` ADR-1 for the full reasoning.

## Commands

**Local dev stack** (Docker; run from `dev/`):
```bash
cd dev && docker compose up -d               # WordPress at http://localhost:8890
docker compose exec cli wp plugin activate sssihms-lms
```
Project name is `sslms-lms` (isolated volumes — safe alongside other compose projects
on the same machine).

**Lint** (no build step; plain PHP, no Composer/npm deps):
```bash
php -l path/to/file.php                      # single file
find sssihms-lms -name "*.php" -exec php -l {} \;   # whole plugin
```

**Test** — there is no PHPUnit suite. Correctness is verified with
`dev/smoke-test.php`, a single script that exercises the real REST/module layer as
different WP users and prints `PASS`/`FAIL` per SPEC requirement (e.g.
`PASS  F8.2 ADMIN cannot read private entry`). Run it against the local stack:
```bash
cd dev
docker compose exec -T cli wp db query "DELETE FROM wp_sslms_courses; DELETE FROM wp_sslms_enrollments; ..."  # reset seed data between runs (see file for full table list)
docker compose cp smoke-test.php cli:/tmp/smoke.php
docker compose exec -T cli wp eval-file /tmp/smoke.php
```
It must end with `RESULT: ALL PASS`. Add new assertions to this file for new behavior
rather than starting a separate test framework — it's the established pattern.

**Course content import** — `dev/import-courses.php` bulk-creates courses from a JSON
file (format documented in `dev/COURSE_IMPORT_FORMAT.md`); idempotent, skips existing
titles, transactional per course:
```bash
wp eval-file import-courses.php /absolute/path/to/courses.json --url=site.example
```

## Architecture

**Plugin bootstrap is convention-based, not a manual registry.** `sssihms-lms.php`
`require`s the core class files explicitly, then glob-`require`s every `.php` file in
`includes/modules/`, `includes/rest/`, `includes/interfaces/`, `admin/`, and `public/`.
**Dropping a correctly-named file into one of those directories is the entire
registration step** — each file self-registers its own hooks at the bottom
(`SSLMS_Thing::init();`). There is no central switchboard to edit.

**Layering, strictly one-directional:**
```
portal shortcodes / wp-admin screens
        │
        ▼
REST controllers (includes/rest/, namespace sslms/v1, extend SSLMS_REST_Base)
        │
        ▼
domain modules (includes/modules/) — all business logic + capability/ownership checks
        │
        ▼
custom DB tables via SSLMS_DB — plain $wpdb + prepare() elsewhere
```
Cross-module communication is via WordPress actions/filters, not direct coupling — e.g.
the course player fires `do_action('sslms_lesson_view', $lesson, $course)` and
`do_action('sslms_course_view', $course)` so Module B (quizzes) can append its own UI
without Module A knowing quizzes exist. `sslms_dashboard_cards` (filter) is how each
portal module contributes a learner-dashboard card. `sslms_quiz_graded` and
`sslms_course_completed` are the actions that drive progress/certificate side effects.
When adding a feature that spans modules, look for the existing hook before adding a
direct method call across module files.

**The database schema is treated as frozen.** It was locked after the initial 7-agent
verification pass (see `DECISIONS.md`). Every table lives under one `sslms_` prefix
(`SSLMS_DB::table('name')` resolves it including multisite blog prefixes). Adding a
column/table is a deliberate, flagged decision, not a routine edit — if a feature seems
to need one, say so rather than adding it silently.

**Security model, enforced at two layers on every mutating path:** (1) a WordPress
capability check in the REST `permission_callback`, then (2) an object-level ownership/
scope check inside the module method itself (e.g. an instructor may only edit quizzes
for courses they created — `SSLMS_Courses::can_edit()`; a preceptor may only sign off
learners linked via an explicit `sslms_relationships` row, never by course or
department). The REST layer capability check alone is never sufficient — this
duplication is intentional defense-in-depth, not redundancy to clean up.

**Confidentiality gate pattern (reflective journals, `class-sslms-journals.php`):** one
function, `SSLMS_Journals::user_can_read_entry()`, is the single source of truth for
whether a viewer may read a journal entry, and every read path (single fetch, list
query, comments, admin aggregate counts) either calls it or duplicates its exact logic
in scoped SQL. Administrators have **no** content-access override for private or
mentor-visible entries — by design, not oversight (DPDP data minimisation) — they get
counts/metadata only. Any new sensitive-data feature should follow this same
single-gate-function shape rather than scattering permission checks.

**NABH/compliance retention guards:** records with signed history block deletion rather
than allowing it — a checklist item with recorded sign-offs, a rotation with hour logs,
a quiz with attempts, a course with enrollments all refuse `delete()` (returns
`WP_Error`) once they have dependent history; the caller is expected to archive/
deactivate instead. Don't relax these without being asked.

**Audit log is append-only.** `SSLMS_Audit::log($action, $object_type, $object_id,
$summary)` is called after every compliance-relevant mutation. There is deliberately no
update/delete code path against `sslms_audit_log` anywhere in the plugin — don't add one.
Never put sensitive content (journal bodies, document contents) in the `$summary`
string, only metadata.

**Google Drive video embeds:** lesson videos may be Drive share links; the parser
(`SSLMS_Portal_Courses::google_drive_file_id()`) handles both `/file/d/ID/view` and
`open?id=ID` / `uc?id=ID` formats and renders via Drive's own `/preview` iframe — files
stay on Drive, sharing permissions apply as normal, nothing is downloaded or proxied.
Note: Drive enforces a per-account streaming/bandwidth quota on publicly link-shared
files; heavy automated testing against the same file can trip "Unable to load video"
for everyone until it resets (not a code or permissions bug when this happens).

## Working conventions (from `CONVENTIONS.md` — read it in full before adding a module)

- File naming signals registration point: `class-sslms-<thing>.php` (modules),
  `class-sslms-rest-<thing>.php` (REST), `class-sslms-admin-<thing>.php` (admin),
  `class-sslms-portal-<thing>.php` (public).
- REST responses go through `SSLMS_REST_Base::ok()`/`fail()`; CSV exports through
  `SSLMS_REST_Base::send_csv()`. Every writable REST arg needs a
  `sanitize_callback`/`validate_callback`.
- Portal shortcode output is wrapped in `SSLMS_Portal::wrap($title, $html)`; page URLs
  come from `SSLMS_Portal::page_url($key, $query)`, never hardcoded.
- Dates display as `SSLMS_DB::fmt_date()` (DD-MMM-YYYY) throughout, matching the
  hospital's expected format.
- No new JS framework or CSS framework — vanilla JS using the existing
  `assets/js/sslms.js` `SSLMS.api()` helper (auto-wires any
  `<form data-sslms-endpoint="...">`), Chart.js v4 UMD (already bundled) for charts.
- Multisite-safe by construction: `SSLMS_DB::table()` always resolves the current
  blog's table prefix; the plugin is designed to be activated on one site of a network
  without needing network-activation.

## Phase boundaries

Phase 1 (courses, quizzes, progress/certificates, checklists, hours, journals,
documents, audit log, analytics dashboard, people/relationships) is built and verified.
Phase 2 (SCORM/xAPI/LTI, Zoom/Teams, HR sync, payments) is **interfaces only** —
`includes/interfaces/class-sslms-integrations.php` defines the contracts and a
`sslms_register_integrations` filter-based registry; there is intentionally no working
implementation behind any of them. `ROADMAP-PHASE3.md` describes planned
competency-framework/OSPE/simulation work — not yet built; treat it as a spec to build
toward, not existing behavior.
