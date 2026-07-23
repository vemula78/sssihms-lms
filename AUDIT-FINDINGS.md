# Audit Findings

## Audit 2026-07-23 — Codex

*(Note: Codex's session completed the audit but its file write failed silently; this
section was recovered verbatim from its session log by Claude and appended on its
behalf. Triage annotations added below each finding.)*

### Finding 1: Any authenticated user can stream any credential document
- Severity: MAJOR
- File: sssihms-lms/includes/rest/class-sslms-rest-documents.php:78
- Requirement: SPEC F9.2; upload security and object-level access control
- Evidence: The file route's permission callback accepts every logged-in user (lines 81–85). The handler then loads the requested document by ID and delegates to `SSLMS_Documents::can_view()`, whose only non-owner checks are `sslms_manage` or the broad `sslms_author_courses` capability; it does not verify an instructor relationship or course scope.
- Failing scenario: A logged-in instructor/course author sends `GET /wp-json/sslms/v1/documents/42/file`, where document 42 belongs to an unrelated learner. The private credential file is streamed successfully.
- **Triage: CONFIRMED → FIXED same day.** `can_view()` now allows owner + `sslms_manage` only; the `sslms_author_courses` branch removed (DPDP minimisation). Regression test added to `dev/smoke-test.php`.

### Finding 2: Rotation detail and listing endpoints expose unrelated learners' clinical-hour records
- Severity: MAJOR
- File: sssihms-lms/includes/rest/class-sslms-rest-hours.php:39
- Requirement: SPEC F7.1–F7.4; access control/object-level scoping
- Evidence: `GET /rotations` and `GET /rotations/{id}` require only `sslms_enroll_learners`. `list_rotations()` filters only by caller-supplied `user_id`/`preceptor_id` and otherwise returns all rotations, while `get_rotation()` returns the selected rotation and all its logs without checking ownership, assigned preceptor, relationship, or `sslms_manage`.
- Failing scenario: A user holding `sslms_enroll_learners` requests `GET /wp-json/sslms/v1/rotations/17` (or lists rotations without filters) and receives another learner's department, dates, preceptor, totals, and hour-log activity notes.
- **Triage: CONFIRMED → FIXED same day.** `get_rotation` now requires owner / assigned reviewer (`reviewer_in_scope`) / `sslms_view_reports` / `sslms_manage` and 404s otherwise; `list_rotations` filters results to owned/reviewed rotations for callers without reports/manage. Regression tests added.

### Finding 3: Enrollment managers can alter or delete another learner's rotation by swapping its ID
- Severity: MAJOR
- File: sssihms-lms/includes/rest/class-sslms-rest-hours.php:46
- Requirement: NABH compliance-record integrity; access control/object-level scoping
- Evidence: `PUT /rotations/{id}` and `DELETE /rotations/{id}` use only `sslms_enroll_learners`. Their handlers pass the ID directly to `SSLMS_Hours::update_rotation()`/`delete_rotation()`, and those module methods update/delete solely by `id`; neither checks the acting user's relationship to the rotation or requires `sslms_manage`. The delete guard only rejects rotations that already have logs.
- Failing scenario: An enrollment manager submits `DELETE /wp-json/sslms/v1/rotations/17` for an unrelated rotation with no hour logs, or changes its preceptor/required hours via `PUT`. The rotation is deleted or changed without an object-level authorization check.
- **Triage: CONFIRMED → FIXED same day.** `PUT`/`DELETE /rotations/{id}` now require `sslms_manage` (rotation creation stays with `sslms_enroll_learners`). Regression tests added.

VERDICT: 0 blockers, 3 major, 0 minor — all three majors fixed 23-Jul-2026, verified by smoke test.
