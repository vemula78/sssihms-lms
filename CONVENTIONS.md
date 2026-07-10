# CONVENTIONS.md — contract for module builders

Read SPEC.md and ARCHITECTURE.md first. Core scaffold already exists — do NOT modify any
core file: `sssihms-lms.php`, `includes/class-sslms-*.php`, `includes/rest/class-sslms-rest-base.php`,
`public/class-sslms-portal.php`, `admin/class-sslms-admin-menu.php`, `uninstall.php`,
`assets/css/sslms.css`, `assets/js/sslms.js`. The DB schema is FINAL (see
class-sslms-install.php); do not add tables or columns — if the schema truly blocks you,
note it in your final report instead of changing it.

## Registration (no shared-file edits ever)
Files in `includes/modules/`, `includes/rest/`, `admin/`, `public/` are auto-required by
the bootstrap and must self-register hooks at the bottom of the file
(`SSLMS_Xyz::init();`). Create ONLY files whose names start with your assigned prefix.

- Admin screens: `add_submenu_page` with parent `'sslms'` on `admin_menu` priority 20+.
  Capability per SPEC (authoring = `sslms_author_courses`, etc.).
- REST: class extends `SSLMS_REST_Base`, registers on `rest_api_init`, namespace
  `SSLMS_REST_Base::NS`. Use `self::can('cap')` / `self::can_any([...])` permission
  callbacks, `self::ok()` / `self::fail()` responses, `self::send_csv()` for exports.
  Declare `args` with `sanitize_callback`/`validate_callback` on every writable param.
- Portal shortcodes: return `SSLMS_Portal::wrap($title, $html)`. Page URLs via
  `SSLMS_Portal::page_url($key, $query)` — keys: dashboard, my_courses, course,
  my_checklists, my_hours, my_journal, my_documents, my_certificates, verify.
- Dashboard cards: hook filter `sslms_dashboard_cards`, append
  `['title' => ..., 'html' => ...]` for the current user's pending/alert items.

## Shared helpers
- Tables: `SSLMS_DB::table('courses')` etc.; `SSLMS_DB::insert/update/get_row/get_row_by/delete/now/fmt_date`.
- Everything else: `$wpdb` with `$wpdb->prepare()` — ALWAYS prepared statements.
- Audit every compliance-relevant mutation: `SSLMS_Audit::log($action, $object_type, $object_id, $summary)`.
  Action strings: lowercase snake, e.g. `enrollment_created`, `quiz_submitted`,
  `checklist_item_signed`, `hours_approved`, `document_verified`, `journal_visibility_changed`,
  `certificate_issued`.
- JS: forms with `data-sslms-endpoint="path"` auto-POST via REST (see assets/js/sslms.js);
  or call `SSLMS.api(path, {method, body})`. Multipart forms are sent as FormData.
- Dates display: `SSLMS_DB::fmt_date()` (DD-MMM-YYYY).

## Cross-module contracts (implement exactly these signatures)
Module A (`SSLMS_Courses`, `SSLMS_Enrollments` in includes/modules/):
- `SSLMS_Courses::get( int $id ): ?object`
- `SSLMS_Courses::modules( int $course_id ): array` (ordered)
- `SSLMS_Courses::lessons( int $module_id ): array` (ordered)
- `SSLMS_Courses::lesson_ids( int $course_id ): array` (all lesson ids)
- `SSLMS_Enrollments::is_enrolled( int $course_id, int $user_id ): bool` (status=active|completed)
- `SSLMS_Enrollments::enroll( int $course_id, int $user_id, int $by ): int|WP_Error`
- `SSLMS_Enrollments::instructor_course_ids( int $user_id ): array` (courses created_by user)
- Course player lesson view must call `do_action('sslms_lesson_view', $lesson, $course)` after
  the content so B (quiz launcher) and C (mark-complete button) can append UI.

Module B (`SSLMS_Quizzes`):
- `SSLMS_Quizzes::for_course( int $course_id ): array` (all quizzes incl. lesson quizzes)
- `SSLMS_Quizzes::user_passed( int $quiz_id, int $user_id ): bool`
- Fire `do_action('sslms_quiz_graded', $attempt_object)` after grading an attempt.

Module C (`SSLMS_Progress`, `SSLMS_Certificates`):
- `SSLMS_Progress::lesson_done( int $lesson_id, int $user_id ): bool`
- `SSLMS_Progress::course_pct( int $course_id, int $user_id ): int`
- `SSLMS_Progress::recalculate( int $course_id, int $user_id ): void` — sets enrollment
  completed when all lessons done + all required quizzes passed; fires
  `do_action('sslms_course_completed', $enrollment_object)`.
- Hook own recalc to lesson completion and to `sslms_quiz_graded`.
- `SSLMS_Certificates` hooks `sslms_course_completed` and issues the certificate.
- Call A/B contract functions; they exist at runtime (all files loaded together). Guard
  optional UI hooks with `class_exists()` only where a module could be absent in tests.

Module E (`SSLMS_Journals`):
- Single gate `SSLMS_Journals::user_can_read_entry( object $entry, int $user_id ): bool`
  used by EVERY read path (list SQL must ALSO scope rows). Mentor visibility requires a
  `relationships` row with rel_type='mentor' where user_id = author, related_user_id = mentor.
  Instructor visibility: instructors of courses the author is enrolled in, i.e. author is
  enrolled in a course whose created_by = viewer (or viewer has sslms_manage? NO — admins
  never read private; admins DO read instructor-visible and mentor-visible entries? NO —
  per SPEC F8.2 admins see counts only for private; for mentor/instructor entries admins
  also have no content access unless they are the assigned mentor/instructor. Keep it strict.)

## Security rules (non-negotiable)
- Capability check in every permission_callback; object-level ownership checks in the
  handler (e.g. preceptor may only sign for learners with a matching relationships row).
- Escape all output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for rich lesson body.
- Sanitize all input: `sanitize_text_field`, `absint`, `wp_kses_post`, etc.
- Never expose: correct answers pre-submission, other users' data, private journal bodies,
  raw file paths of documents.
- Validate at boundaries only (REST args, upload MIME/size); no extra validation layers.

## Style
- WordPress PHP coding style, tabs, `SSLMS_` class prefix, one class per file named
  `class-sslms-<thing>.php` (modules), `class-sslms-rest-<thing>.php` (REST),
  `class-sslms-admin-<thing>.php` (admin), `class-sslms-portal-<thing>.php` (public).
- Admin list/edit screens: plain WP-admin HTML (wrap class="wrap", WP_List_Table not
  required — simple tables fine). Forms POST to REST via the shared JS, or classic
  admin-post with nonces — pick REST for consistency.
- No new JS/CSS frameworks; extend assets/ only with files prefixed by your module name.
- `php -l` clean on every file you create.
- NO features beyond SPEC. NO abstractions beyond what your module needs.
