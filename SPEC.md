# SSSIHMS Learning Management System — Functional Specification

> **Provenance note:** The original SPEC.md was not present in this folder at kickoff; this
> document was derived from the project owner's written scope decisions (10-Jul-2026
> kickoff message) plus defaults recorded in DECISIONS.md. The owner supplied the full
> functional specification text later the same day; it is reconciled in **section 7
> (traceability matrix)**. The owner's Phase-1 scope decisions take precedence: full-spec
> items outside them are mapped to Phase 2 stubs or to the explicit backlog — nothing is
> silently dropped.

## 1. Context

SSSIHMS Whitefield (Sri Sathya Sai Institute of Higher Medical Sciences) is a charitable
tertiary-care hospital in Bangalore; all care is free of charge. The LMS serves three
education tracks:

1. **Clinical** — medical/nursing/allied-health training with compliance and clinical
   competency requirements.
2. **Allied health** — discipline-specific practical evaluation.
3. **Chaplaincy** — spiritual/formation education emphasising reflection and mentorship.

Learners include clinical staff on shifts (**mobile access is essential**), students on
rotations, and chaplaincy trainees.

## 2. Constraints

- Stack: PHP/WordPress plugin on the hospital's existing WordPress + MySQL infrastructure
  (see ARCHITECTURE.md ADR-1). Low-maintenance for a small charitable-hospital IT team.
- Deployment: on-prem Ubuntu VM (hospital standard). No external SaaS dependencies; no CDN
  assets — everything bundled.
- Compliance: India — DPDP Act 2023 for personal data; NABH audit-report expectations
  (exportable training/competency records, tamper-evident audit trail). No HIPAA.
- Mobile-first responsive UI; no native app.
- No payments in Phase 1 (all education is free, consistent with the hospital's mission).

## 3. Roles

| Role | Description |
|---|---|
| **Admin** | WP administrator. Full control: users, courses, settings, reports, audit log. |
| **Instructor** | Authors courses/modules/lessons/quizzes/question banks; enrols learners; views progress of learners in own courses; sees instructor-visible journals of own students. |
| **Student** | Takes courses/quizzes; logs hours; maintains journal; uploads documents; views own progress/certificates. |
| **Supervisor / Preceptor** | Signs off skills-checklist items; approves/rejects clinical hour logs for assigned learners; views assigned learners' competency status. |
| **Evaluator** | Conducts allied-health practical evaluations (checklist sign-offs) for assigned learners. |
| **Mentor / Spiritual director** | Reads mentor-visible journal entries of assigned mentees and comments on them. |

A user may hold multiple roles. Assignment relationships (mentor↔mentee,
preceptor↔learner) are explicit records, not inferred.

## 4. Phase 1 functional requirements

### F1. User & role management
- F1.1 Six roles as above; admin manages users through the standard WordPress users screen
  (roles registered by the plugin), plus an LMS "People" admin screen for LMS-specific
  assignments (mentor↔mentee, preceptor↔learner) and track membership.
- F1.2 Every learner has a profile: name, employee/student ID, discipline, track(s).
- F1.3 Deactivating a user preserves their training records (NABH retention).

### F2. Course / module / lesson authoring
- F2.1 Hierarchy: Course → Modules (ordered) → Lessons (ordered).
- F2.2 Course fields: title, description, track (clinical / allied / chaplaincy), status
  (draft/published/archived), passing requirement (all lessons complete + required quizzes
  passed).
- F2.3 Lesson content: rich text, optional embedded video (self-hosted or URL), optional
  file attachments. Renders correctly on a phone.
- F2.4 Enrollment: admin/instructor enrols individuals; a course may be marked
  "open enrollment" so eligible learners can self-enrol.
- F2.5 Only instructors/admins author; students never see draft/archived courses.

### F3. Quizzes, question banks, randomization
- F3.1 Question banks hold reusable questions: MCQ (single), MCQ (multiple), true/false.
- F3.2 A quiz draws N random questions from its bank per attempt; option order shuffled.
- F3.3 Quiz settings: pass percentage, max attempts (0 = unlimited), optional time limit.
- F3.4 Attempts are stored verbatim (questions served, answers given, score, timestamps)
  — auditability for NABH.
- F3.5 Server-side grading only; correct answers never sent to the client before submission.
- F3.6 A quiz may be attached to a lesson (gate) or to a course (final assessment).

### F4. Progress tracking & completion reports
- F4.1 Lesson completion is recorded per user with timestamp.
- F4.2 Course completion = all lessons complete AND all required quizzes passed; recorded
  with timestamp.
- F4.3 Reports: per-course roster with status/percentage/dates; per-user transcript;
  CSV export of both. Instructor sees own courses; admin sees all.

### F5. Certificates
- F5.1 Auto-issued on course completion; certificate has a unique verification code.
- F5.2 Printable A4 certificate (HTML print view → PDF via browser) with hospital name,
  learner name, course, completion date, code. SSSIHMS branding (white, Playfair/Lato).
- F5.3 Public verification endpoint: enter code → confirms learner name, course, date.

### F6. Skills checklists with preceptor sign-off
- F6.1 Checklist templates: titled list of ordered competency items, tagged by discipline.
- F6.2 Assigned to learners; each item is rated by a preceptor/evaluator on a 3-level
  scale (needs practice / performs with supervision / competent) with optional note.
- F6.3 Sign-off records signer identity + timestamp; a completed checklist (all items
  "competent") is locked from further edits except by admin.
- F6.4 Learner sees own checklist status; preceptor/evaluator sees assigned learners only.

### F7. Clinical hours / rotation tracking
- F7.1 Rotations: learner, department, start/end dates, assigned preceptor, required hours.
- F7.2 Learners log dated hour entries (date, hours, activity note) against a rotation.
- F7.3 Preceptor approves/rejects each entry; totals show approved vs required.
- F7.4 CSV export per learner and per rotation.

### F8. Reflective journals with confidentiality controls
- F8.1 Entries have per-entry visibility: **private** (author only), **mentor-visible**
  (author + assigned mentor(s)), **instructor-visible** (author + course instructors).
- F8.2 Visibility is enforced server-side on every read path. Admins do NOT get content
  access to private entries (DPDP data-minimisation); they see only counts/metadata.
- F8.3 Mentors can comment on entries they can see; author is notified on dashboard.
- F8.4 Author may change an entry's visibility or delete own entries.

### F9. Document uploads with expiry alerts
- F9.1 Learners upload credential documents: license/registration, BLS/ACLS, immunization,
  background check, other. Fields: type, file (pdf/jpg/png ≤ 10 MB), issue date, expiry date.
- F9.2 Files stored outside the public uploads URL space; served only via an
  access-controlled endpoint (owner, admin, and — for verification — instructors).
- F9.3 Admin verifies documents (verified flag + who/when).
- F9.4 Status auto-computed: valid / expiring (≤ 30 days) / expired. Daily WP-cron flags
  expiring docs: dashboard alert for the owner, digest email to admin.
- F9.5 Compliance report: matrix of learners × required doc types with status; CSV export.

### F10. Audit logs
- F10.1 Append-only log of security- and compliance-relevant actions: logins to LMS pages,
  enrolments, quiz submissions, sign-offs, hour approvals, document verifications, journal
  visibility changes, certificate issuance, admin edits/deletions.
- F10.2 Each row: actor, action, object type/id, summary, IP, timestamp.
- F10.3 Admin-only viewer with filters (user, action, date range) + CSV export. No
  update/delete UI for log rows.

### F11. Analytics dashboard
- F11.1 Admin/instructor dashboard: enrolment & completion rates per course, compliance
  overview (expiring/expired documents count), overdue items (pending sign-offs, pending
  hour approvals), quiz pass rates.
- F11.2 Charts render from bundled (local) chart library; degrade to tables without JS.

### F12. Learner experience (cross-cutting)
- F12.1 Mobile-first learner portal (front-end pages): My courses, course player,
  My checklists, My hours, My journal, My documents, My certificates.
- F12.2 Works on a phone browser over hospital Wi-Fi; no external network calls.

## 5. Phase 2 — stub interfaces only (DO NOT implement)

Define PHP interfaces + registration hooks, with no working implementation:
- SCORM/xAPI/LTI package import & statement forwarding (`SSLMS_Scorm_Adapter_Interface`).
- Zoom/Teams live-session integration (`SSLMS_Meeting_Provider_Interface`).
- HR system sync — user provisioning/deactivation (`SSLMS_HR_Sync_Interface`).
- Payments (`SSLMS_Payment_Gateway_Interface`) — placeholder only; not used in Phase 1.

## 6. Non-functional requirements

- **N1 Security**: capability checks + nonces on every mutating request; prepared
  statements for all SQL; upload MIME/extension allow-list; no correct answers or private
  journal content in any response the viewer isn't entitled to.
- **N2 DPDP 2023**: consent notice at first LMS login (purpose of processing); data
  minimisation (admins can't read private journals); erasure = WP user deletion
  anonymises journal/audit references while preserving compliance records required for
  NABH (documented retention exception).
- **N3 NABH**: training records, competency sign-offs and audit trail exportable as CSV.
- **N4 Validation at system boundaries only**: REST input and file uploads. No speculative
  internal validation layers.
- **N5 No feature creep**: nothing beyond this spec; Phase 2 is stubs only.

## 7. Traceability — full specification vs this build

Every item of the owner-supplied full specification, mapped. **P1** = implemented in this
build (F-reference), **P2** = stub interface only per owner decision, **BL** = backlog:
explicitly out of Phase 1 by the owner's scope decisions, listed here so it is a visible
future decision, not an omission.

### Core LMS
| Spec item | Status |
|---|---|
| Roles: admin, instructor, student, supervisor/preceptor, evaluator (+ mentor/spiritual director) | P1 — F1 |
| Course creation: modules, lessons, videos, PDFs | P1 — F2 (video URL/self-hosted, attachments incl. PDF) |
| Quizzes | P1 — F3 |
| Assignments (essay/file submissions, grading) | BL — not in Phase-1 scope decisions |
| Mobile-friendly access on shifts/in field | P1 — F12, N-mobile-first |
| Progress tracking & completion reports | P1 — F4 |
| Certificates | P1 — F5 |
| Continuing-education (CME/CNE) credit tracking | BL — certificates only in Phase 1; credit-hours ledger is a natural Phase-2+ extension of F5 |
| Discussion forums, announcements, messaging | BL — omitted from Phase-1 list; journal comments (F8.3) provide the only in-scope dialogue channel |
| Assessments: quizzes/exams, randomized, secure, question banks | P1 — F3 |
| Case studies / case-based & simulation-based learning | P1 as content — authored as lessons (F2.3); no dedicated engine required |
| Reflective journals | P1 — F8 |
| Attendance & live-session tracking | P2/BL — live sessions depend on the Zoom/Teams stub; attendance registers deferred with them |
| SCORM/xAPI/LTI | P2 — stub interface |
| Secure login, audit logs, role-based permissions | P1 — WP auth + F10 + capability model (N1) |
| Analytics dashboards (performance & compliance) | P1 — F11 |
| Zoom/Teams, HR, payments integration | P2 — stub interfaces |
| Email integration | P1 (wp_mail digests, F9.4); richer notifications BL |

### Medical / nursing / allied health
| Spec item | Status |
|---|---|
| Clinical competency tracking, skills checklists, preceptor evaluations | P1 — F6 |
| OSCE-style assessment, rubrics for clinical performance | Partial P1 — 3-level rated checklists with evaluator/preceptor sign-off cover procedural competency; multi-criteria weighted rubrics & OSCE stations BL |
| CME/CNE tracking | BL (above) |
| License & certification renewal tracking | P1 — F9 expiry alerts |
| HIPAA-aware design | N/A — India; DPDP 2023 applies (N2) |
| Mandatory-module course areas (infection control, patient safety, etc.) | P1 as content — authored courses, not features |
| Document uploads: licenses, immunizations, CPR/BLS/ACLS, background checks | P1 — F9 (doc types incl. background check) |
| Rotation/placement tracking, clinical hours, procedure logs | P1 — F7 (hour logs carry an activity/procedure note; a structured procedure-log register is BL) |
| Lab competency sign-offs, equipment training records | P1 via F6 — model as discipline-tagged checklists (e.g. "Ventilator competency") |
| Supervisor feedback forms | Partial P1 — per-item sign-off notes (F6.2) + hour-log review notes (F7.3); standalone free-form evaluation forms BL |
| Portfolio/evidence collection | BL |
| Interprofessional education modules | P1 as content |
| Compliance reports for accreditation | P1 — F4.3, F9.5, F10.3 CSV exports (NABH) |

### Spiritual / chaplaincy
| Spec item | Status |
|---|---|
| Formation course modules (theology, pastoral care, ethics…) | P1 as content — chaplaincy-track courses |
| Reflection journals, private or instructor-reviewed | P1 — F8 (private / mentor / instructor visibility) |
| Mentor/spiritual-director feedback | P1 — F8.3 comments |
| Confidentiality controls for pastoral reflections | P1 — F8.2 (server-side gate, no admin content access) |
| Discussion circles / cohort learning / community forums | BL (forums, above) |
| Live prayer/group sessions | P2 — meeting-provider stub |
| Reading assignments, sermon/reflection submissions, essays, oral assessment | Partial P1 — readings as lessons; reflective writing via journals; graded essay/oral submissions BL (assignments) |
| Audio/video teaching library | P1 as content — lessons with media (F2.3) |
| Certificate programs | P1 — F5 |
| Service-hour / field-ministry tracking | P1 — F7 reused: create a rotation with department = ministry area (e.g. "Ward visits"); preceptor = supervising chaplain |
