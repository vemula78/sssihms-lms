# DECISIONS.md — defaults chosen without user input

- **SPEC.md provenance**: No SPEC.md existed in this folder at kickoff. SPEC.md was
  authored from the owner's written scope message (10-Jul-2026) and these defaults.
  Reconcile if an original spec surfaces.
- **Stack**: WordPress plugin (ADR-1) — chosen from the bracketed options because the
  hospital already runs WP/MySQL and the team already maintains sssihms-bmw-tracker.
- **Checklist rating scale**: 3 levels — needs practice / performs with supervision /
  competent. Checklist completes when every item is rated competent; then locks
  (admin can unlock).
- **Quiz question types**: MCQ single, MCQ multiple, true/false. No essay/short-answer
  in Phase 1 (would need manual grading workflow not in scope).
- **Certificates**: HTML A4 print view (browser print-to-PDF), unique 12-char code,
  public verification page. No server-side PDF library (maintenance burden).
- **Expiry alerts**: "expiring" = within 30 days. Daily WP-cron: learner sees dashboard
  banner; admin gets one digest email. No per-learner emails (avoids mail flood).
- **Journal privacy vs admin**: admins cannot read private entries (DPDP data
  minimisation). Deleting a WP user anonymises journals/audit rows but preserves
  training/competency records (NABH retention) — documented in SPEC N2.
- **Mentor/preceptor/evaluator scoping**: explicit relationship records managed on the
  LMS People screen; nothing inferred from courses or departments.
- **Uploads**: pdf/jpg/png, ≤ 10 MB, stored in wp-content/uploads/sslms-private/ with
  deny-all .htaccess, streamed via authenticated REST route.
- **Portal pages**: created automatically on activation (LMS Dashboard, My Courses,
  Course, My Checklists, My Hours, My Journal, My Documents, My Certificates,
  Verify Certificate). Shortcode-based, mobile-first.
- **Charts**: Chart.js v4 UMD bundled locally (same as BMW tracker); tables as no-JS
  fallback.
- **Dates/formats**: DD-MMM-YYYY display format throughout.
- **Agents used**: orchestrated with Claude subagents (Sonnet for module builds,
  fresh-context verifiers per module). "Codex" is not available in this environment.
- **Roles**: five new WP roles (sslms_student, sslms_instructor, sslms_preceptor,
  sslms_evaluator, sslms_mentor); WP administrator gets all LMS caps. Multi-role users
  supported via WP's multiple-role capability grants.

## Post-verification decisions (10-Jul-2026, after 7-agent review + live smoke test)
- **Quiz authoring scope**: quizzes follow course ownership (instructor edits only own
  courses' quizzes; sslms_manage sees all). Question banks remain SHARED across
  instructors by design (reusable per SPEC F3.1) — bank deletion is blocked while in use.
- **Compliance matrix (F9.5)**: restricted to sslms_manage only. Instructors verifying an
  individual document still stream it via F9.2, but cannot pull the hospital-wide
  credential matrix (DPDP data minimisation).
- **NABH retention guards**: checklist items with recorded sign-offs and rotations with
  hour logs cannot be deleted; templates/rotations block deletion once history exists
  (archive/deactivate instead). Template and rotation edits are audit-logged.
- **Self-approval guard**: a rotation's preceptor can never be the learner themself
  (enforced at create and update).
- **Enrolling into a draft course is allowed** (roster prep before launch) but the course
  is invisible to the learner everywhere until published; lesson completion and quiz
  attempts require published status.
- **Hour-log re-review is permitted** (a preceptor can correct a mistaken decision); every
  decision is separately audit-logged, so the history is traceable.
- **Preceptor relationship** requires the sslms_approve_hours capability specifically
  (evaluator-only users no longer qualify as "preceptor").

## Phase 3a decisions (23-Jul-2026)

- **Schema unfrozen deliberately for Phase 3** per ROADMAP-PHASE3.md: five new tables
  added under the same `sslms_` prefix — `rubrics`, `rubric_criteria`, `rubric_levels`,
  `assessment_records`, `assessment_scores`. DB_VERSION bumped to 1.1.0; the existing
  dbDelta upgrade path handles live sites, and page creation during upgrade is deferred
  to `init` (running `wp_insert_post` at `plugins_loaded` fatals — found by smoke test).
- **`assessment_forms` folded into `rubrics.form_type`** (mini_cex / dops /
  professionalism / communication / ward_feedback / custom) instead of a sixth table —
  a form in the roadmap's sense is exactly one typed rubric; a separate table added a
  join with no data of its own.
- **Level descriptors live per criterion-level cell** (`rubric_levels.criterion_id`),
  not per rubric-wide level, so each cell of the criteria × levels grid can carry its
  own behavioural descriptor as the roadmap requires.
- **Critical-fail rule**: a criterion flagged critical fails the whole assessment when
  the selected level is that criterion's bottom (minimum-marks) level and the criterion
  has more than one level. Signing a failed assessment is allowed — a record of unsafe
  practice is itself a required record.
- **Multi-source scoping**: self-assessment is always permitted on oneself
  (`sslms_learn`); any other source requires an explicit `relationships` row
  (preceptor / evaluator / mentor / peer — `peer` is a new accepted rel_type value,
  no schema change); `sslms_manage` assesses anyone as source `educator`.
- **Draft privacy**: unsigned drafts are visible to their assessor (and admins via the
  record-view gate) but hidden from the learner's portal list until signed, so a
  half-scored observation is never mistaken for feedback.
- **Signed records are immutable for everyone including admins** (no unlock, unlike
  checklists): re-assessment is a new record, matching workplace-based-assessment
  practice and NABH expectations.

## Phase 3b decisions (23-Jul-2026)

- **Three new tables** per roadmap: `competencies` (hierarchical via parent_id, per
  discipline), `competency_map` (evidence tagging), `competency_attainments`
  (append-only sign-off history, latest row wins). DB_VERSION 1.2.0.
- **Attainment is computed on read, never stored** for the evidence side — so existing
  quiz attempts, sign-offs, assessments and hour logs participate retroactively with
  zero migration. Only the human-signed LEVEL is stored, and only sslms_manage may
  sign it (never automatic, per roadmap).
- **Evidence-satisfied definitions**: quiz = any passed attempt; checklist item =
  'competent' sign-off; rubric = signed record with outcome pass; rubric criterion =
  scored at that criterion's top-marks level in any signed record; rotation = approved
  hours ≥ required hours. Versioning of the dictionary deferred — is_active +
  append-only attainments cover the audit need for now; flagged for review in 3c.
- **Starter set seeds only empty disciplines** and never overwrites an edited
  dictionary; it is a convenience import, not a sync.

## Phase 3c decisions (23-Jul-2026)

- **Four new tables** per roadmap: `ospe_exams`, `ospe_stations`, `ospe_candidates`,
  `ospe_scores`. DB_VERSION 1.3.0.
- **Rotation-schedule generation deferred**: station_no ordering + candidate_no gives
  the paper rotation plan; an auto-generated per-slot timetable was cut from v1 as the
  education office runs this on a printed grid today. Revisit if asked.
- **Scores are correctable by the station examiner until the exam is published**
  (every save audit-logged, signer + timestamp kept) — exam-day reality needs quick
  corrections; permanence comes from publication, which locks the whole exam forever
  (no unpublish, even for admins).
- **Moderation is a separate code path**: sslms_manage only, note mandatory, its own
  audit action (`ospe_score_moderated`).
- **Pass rules**: overall % ≥ exam pass_pct AND every scoreable station ≥
  min_station_pct (0 disables the per-station rule). Rest stations excluded.
- **Publishing requires completeness**: every non-absent candidate fully scored, else
  the publish call names the unscored candidate numbers.
- **A station's attached rubric is a scoresheet REFERENCE** shown to the examiner, not
  a second scoring engine — marks are entered once, per station, to keep exam-day
  entry to a single field on a phone.
- **3b integration**: `ospe_station` is a competency evidence type; satisfied only in
  PUBLISHED exams where the candidate met min_station_pct (or >0 marks when unset).

## Phase 3e decisions (23-Jul-2026)

- **Three new tables** per roadmap: `scenarios`, `scenario_nodes`, `scenario_attempts`.
  DB_VERSION 1.4.0.
- **AI-assisted authoring = JSON import, not a runtime integration**: educators paste a
  Claude-drafted scenario (documented JSON shape with string node keys) into the admin
  importer; it always lands as a DRAFT, the editor screen is the clinical-review gate,
  and publishing runs a structural graph validation (one start, ≥1 end, no dangling
  option targets). Zero per-use cost, fully on-prem, human-approved content only.
- **Scoring denominator is path-relative**: earned marks vs the best available option at
  each *visited* decision, so different branches are scored fairly.
- **One open attempt per learner per scenario** (start resumes it); completed attempts
  are immutable and a passed attempt on a published scenario is 3b competency evidence
  (new `scenario` evidence type).
- **Structural node editing UI kept minimal** (review + delete + re-import) — full
  drag-and-drop tree editing was cut from v1; the import format is the authoring path.
- **Phase 3f (live AI simulated patient) deliberately not built** — per roadmap it is
  gated on hospital budget/policy sign-off; the Phase 2 integrations registry is the
  intended attachment point when approved.
