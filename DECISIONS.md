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
