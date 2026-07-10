# Architecture Decision Record & Data Model — SSSIHMS LMS

## ADR-1: Stack — WordPress plugin (PHP) on existing WP/MySQL

**Decision:** Build as a single WordPress plugin (`sssihms-lms`), custom database tables,
WP users/roles/capabilities for identity, WP-admin for authoring/administration, shortcode-
based mobile-first learner portal, REST API (`sslms/v1`) for interactive actions.

**Why (vs Laravel or Node/React):**
- Hospital already runs WordPress + MySQL; IT team already patches/backs up/serves it.
  Zero new runtime, zero new deployment pipeline, one artifact to install.
- Prior in-house pattern exists (sssihms-bmw-tracker follows exactly this shape), so the
  team maintains two plugins with one skill set.
- WP provides for free: authentication, password reset, role/capability model, media
  handling, cron (expiry alerts), email, and an admin UI framework.
- Laravel would be cleaner architecturally but adds composer/artisan/queue operational
  knowledge the team doesn't have; Node/React adds a second runtime and build chain.

**Consequences:** custom tables (not CPTs) for all LMS entities to keep relational
integrity and NABH-grade reporting simple; the plugin must bundle all assets (no CDN).

## ADR-2: Custom tables, not custom post types

Courses/lessons/attempts/sign-offs are relational records with reporting requirements
(rosters, transcripts, compliance matrices, CSV exports). Custom tables with foreign keys
and indexes make those queries direct; CPT/postmeta would scatter them across EAV meta.
WP users remain the identity source.

## ADR-3: Server-rendered portal + REST for mutations

Learner portal pages are server-rendered PHP templates injected via shortcodes on ordinary
WP pages (auto-created on activation). Interactive actions (quiz submit, checklist
sign-off, journal save…) POST to `sslms/v1` REST routes with nonces. No SPA framework —
plain PHP + a small vanilla-JS layer + bundled Chart.js for the dashboard. This is the
lowest-maintenance option for the IT team and matches the BMW tracker.

## ADR-4: Private file storage for credential documents

Credential uploads go to `wp-content/uploads/sslms-private/` guarded by `.htaccess`
deny-all + an index.php, and are streamed through an authenticated REST endpoint with
capability checks. They never get public URLs (DPDP).

## ADR-5: Journal confidentiality

Journal reads go through one gate function (`SSLMS_Journals::user_can_read_entry`) used by
every code path. Private entries are readable by the author only — including not by
admins; admins see counts only. Mentor visibility requires an explicit mentor-assignment
row. This is enforced in SQL WHERE clauses as well as per-object checks.

## ADR-6: Phase 2 = interfaces only

`includes/interfaces/` defines SCORM/meeting/HR-sync/payment interfaces plus a
`sslms_register_integration` filter hook. No implementations, no admin UI beyond a
read-only "Integrations (Phase 2)" placeholder screen.

## Data model

All tables prefixed `{$wpdb->prefix}sslms_`. `users` = WP core table.

```
courses           id, title, slug, description, track ENUM(clinical|allied|chaplaincy),
                  status ENUM(draft|published|archived), open_enrollment TINYINT,
                  created_by → users, created_at, updated_at
modules           id, course_id → courses, title, sort_order
lessons           id, module_id → modules, title, content LONGTEXT, video_url,
                  attachments JSON (media IDs), sort_order, est_minutes
enrollments       id, course_id, user_id, enrolled_by, status ENUM(active|completed|withdrawn),
                  enrolled_at, completed_at        UNIQUE(course_id, user_id)
question_banks    id, title, created_by
questions         id, bank_id → question_banks, qtype ENUM(mcq|multi|tf), prompt,
                  options JSON, correct JSON, points, is_active
quizzes           id, course_id, lesson_id NULL, bank_id, title, num_questions,
                  pass_pct, max_attempts, time_limit_min, is_required
quiz_attempts     id, quiz_id, user_id, started_at, submitted_at NULL, question_ids JSON,
                  answers JSON, score_pct, passed TINYINT
lesson_progress   id, lesson_id, user_id, completed_at   UNIQUE(lesson_id, user_id)
certificates      id, enrollment_id → enrollments, cert_code CHAR(12) UNIQUE, issued_at
checklists        id, title, discipline, description, is_active
checklist_items   id, checklist_id, item_text, sort_order
checklist_assignments id, checklist_id, user_id, assigned_by, assigned_at,
                  completed_at NULL, locked TINYINT    UNIQUE(checklist_id, user_id)
checklist_signoffs id, assignment_id, item_id, rating ENUM(needs_practice|with_supervision|competent),
                  note, signed_by → users, signed_at   UNIQUE(assignment_id, item_id)
rotations         id, user_id, department, start_date, end_date, preceptor_id → users,
                  required_hours DECIMAL
hour_logs         id, rotation_id, user_id, log_date, hours DECIMAL(5,2), activity,
                  status ENUM(pending|approved|rejected), reviewed_by NULL, reviewed_at,
                  review_note
journal_entries   id, user_id, title, body LONGTEXT, visibility ENUM(private|mentor|instructor),
                  created_at, updated_at
journal_comments  id, entry_id, author_id, body, created_at
relationships     id, user_id (learner/mentee), related_user_id (mentor/preceptor/evaluator),
                  rel_type ENUM(mentor|preceptor|evaluator), created_by, created_at
                  UNIQUE(user_id, related_user_id, rel_type)
documents         id, user_id, doc_type ENUM(license|bls|acls|immunization|other),
                  file_path, original_name, mime, issue_date, expiry_date NULL,
                  verified_by NULL, verified_at NULL, uploaded_at
profiles          user_id PK → users, staff_id, discipline, tracks SET/CSV, consent_at
audit_log         id, user_id, action VARCHAR(64), object_type, object_id, summary,
                  ip VARBINARY(16), created_at        (append-only; no UPDATE/DELETE paths)
```

## Plugin layout

```
sssihms-lms/
  sssihms-lms.php            bootstrap: constants, autoload-lite requires, hooks
  uninstall.php              preserves data unless "delete on uninstall" option set
  includes/
    class-sslms-install.php  dbDelta schema, roles/caps, pages, cron, defaults
    class-sslms-roles.php    role/capability registry
    class-sslms-audit.php    audit writer + admin viewer query
    class-sslms-db.php       thin table-name + query helpers
    rest/                    one controller per module (courses, quizzes, progress,
                             certificates, checklists, hours, journals, documents, dashboard)
    modules/                 domain logic per module (grading, completion, expiry…)
    interfaces/              Phase-2 stubs
  admin/                     WP-admin screens (menu, list tables, editors, reports, audit)
  public/                    shortcodes, portal templates, certificate/verify views
  assets/                    css (mobile-first), js (vanilla), chart.js (bundled UMD)
```
