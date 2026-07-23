# Phase 3 Roadmap — Competency-Based & Affective Learning, OSPE, KPIs, Simulation

Response to reviewer feedback (21-Jul-2026): *"I need a system built to support affective
and competency-based learning, OSPEs, real-time KPIs, and if possible AI-driven
simulation scenarios."*

## Where the current build already answers this (worth stating before building more)

| Reviewer's concern | Already in the system |
|---|---|
| "Not just read → test → certificate" | Skills checklists with preceptor/evaluator sign-off (observed practice, not MCQs); clinical hours with supervisor approval; certificates require both lessons AND assessments |
| Affective learning | Reflective journals with private/mentor/instructor confidentiality tiers; mentor commentary loop; chaplaincy formation track designed around reflection, not testing |
| Auditability for competency claims | Every sign-off records signer + timestamp, append-only audit log, NABH retention guards |

The gaps are: no structured **rubrics** (only 3-level checklist ratings), no **competency
framework** connecting evidence to defined competencies, no **station-based exam (OSPE)**
workflow, KPIs are per-page-load without department drill-down, and no **simulation**.

## Design principles (constraints carried forward)

- Same stack (WordPress plugin, custom tables via the existing dbDelta upgrade path) —
  no new runtime for hospital IT.
- Prototype → clinical-educator review → deploy, per hospital workflow. Each phase is
  independently shippable and demoable.
- DPDP: simulations use fictional patients only; learner performance data follows the
  existing confidentiality patterns. NABH: every assessment produces exportable,
  signed, timestamped records.
- Cost-sensitive: AI is used at authoring time by default (zero runtime cost);
  learner-facing AI is an explicitly budget-capped pilot, off by default.

## Phase 3a — Rubrics engine + affective assessment forms (foundation for everything)

The single most load-bearing piece. Extends the checklist concept to full rubrics:

- Rubric templates: N criteria × M performance levels, each cell with a descriptor and
  marks; criterion weights; optional critical-fail criteria ("unsafe practice = fail
  regardless of total").
- Assessment forms built from rubrics: mini-CEX, DOPS (procedural), professionalism/
  communication observation forms (the affective domain), ward-behaviour feedback.
- Multi-source assessment: the same rubric completable by self, peer, preceptor, and
  mentor, with source recorded — enables 360° views of affective competencies.
- Every completed rubric = one evidence record: learner, assessor, role, context
  (course/rotation/encounter), scores, free-text feedback, timestamp, signature.
- New tables: `rubrics`, `rubric_criteria`, `rubric_levels`, `assessment_forms`,
  `assessment_records`, `assessment_scores`.

## Phase 3b — Competency framework + learner passport

- Competency dictionary: hierarchical (domain → competency → milestone), per
  discipline, versioned. Ships with an editable starter set for nursing (INC-aligned
  domains), allied health, and chaplaincy formation.
- Evidence mapping: any quiz, checklist item, rubric criterion, OSPE station, or hour
  log can be tagged to competencies. Existing data participates retroactively.
- Attainment model: each competency shows current level (not yet assessed → novice →
  competent → proficient) computed from mapped evidence, with the evidence list one tap
  away. Sign-off of final attainment stays human (department educator), never automatic.
- Learner "Competency Passport" portal page: progress per domain, evidence trail,
  printable summary for NABH/appraisals. Educator view: cohort matrix (learners ×
  competencies) with gaps highlighted.
- New tables: `competencies`, `competency_map`, `competency_attainments`.

## Phase 3c — OSPE module (station-based practical exams)

Builds directly on 3a rubrics + existing evaluator role and scoping:

- Exam blueprint: an OSPE = ordered stations; each station has type (procedure /
  question / rest), duration, max marks, and an attached rubric or structured
  scoresheet.
- Scheduling: candidate list (from enrollments/cohort), examiner assignment per
  station, generated rotation schedule.
- Exam-day mobile scoring: examiner logs in on a phone/tablet, sees only their station
  and current candidate, scores against the rubric, signs, next candidate. Works on
  hospital Wi-Fi; scores save per-station so a dropped connection loses nothing.
- Aggregation & results: per-station and overall pass rules (minimum per station +
  aggregate), moderation screen for the exam coordinator (flag/adjust with audit
  trail), publication to learners, automatic feed into competency passport (3b) and
  certificates where configured.
- Exports: full mark-sheets per candidate and per exam (CSV/print) for university/NABH
  submission.
- New tables: `ospe_exams`, `ospe_stations`, `ospe_candidates`, `ospe_scores`.

## Phase 3d — KPI dashboard v2 (cheap, do in parallel)

"Real-time" right-sized for hospital ops — periodically self-refreshing dashboards
(30–60s polling), not websocket infrastructure the IT team would have to maintain:

- Department/cohort dimension added to profiles → every KPI filterable and drillable
  by department.
- KPI set: compliance % (credentials valid), competency attainment % by department,
  overdue sign-offs and hour approvals with ageing, OSPE pass rates, active-learner
  trend, expiring-credential forecast (30/60/90 days).
- Threshold alerts: configurable red lines (e.g. "BLS coverage in a unit < 90%")
  surfacing on the dashboard and in the admin digest email.
- Board/TV mode: a full-screen auto-rotating view for the education office.

## Phase 3e — Simulation, Tier A: branching scenarios (AI-assisted authoring, zero runtime cost)

- Scenario engine: authored decision-tree cases — patient presentation, decision
  points, consequences, embedded media, scoring per path, structured debrief at the end.
  Deterministic, works fully on-prem, no per-use cost, results feed competency map.
- AI at authoring time: educators give learning objectives + case outline; Claude
  drafts the branching scenario (all branches, distractors, debrief text) into the
  scenario editor for clinical review before publishing. Human-approved content only.
- New tables: `scenarios`, `scenario_nodes`, `scenario_attempts`.

## Phase 3f — Simulation, Tier B: interactive AI pilot (explicitly gated)

- Chat-based simulated patient / viva examiner powered by the Claude API: learner
  interviews a fictional patient, then receives structured rubric-based feedback
  generated against educator-defined criteria.
- Guardrails: fictional cases only (no PHI), monthly budget cap enforced in the
  plugin, feature-flagged per course, transcripts stored under the journal-grade
  confidentiality pattern, human educator reviews AI feedback before it counts toward
  any competency.
- Run as a 1-department pilot with explicit cost reporting before any wider rollout.
- Phase 2 stubs (`SSLMS_Integrations`) already provide the registration pattern for
  this integration.

## Sequencing and rationale

```
3a Rubrics ──────────► 3b Competency framework ──► 3c OSPE
   (foundation)            (meaning layer)             (exam workflow)
3d KPI v2 ───────────  parallel, independent, quick win
3e Scenarios Tier A ─  after 3a (uses scoring), before any live-AI spend
3f AI pilot ─────────  last; needs hospital sign-off on budget + policy
```

Each phase follows the same method as Phase 1: build → fresh-context verification
against this document → live smoke test → deploy to demo → hospital review.

## What to show the reviewer meanwhile

The demo already supports a stronger story than "knowledge warehousing": walk them
through a checklist sign-off on a phone (observed competency), a mentor-visible journal
with commentary (affective/formation), and the audit log behind a certificate. Frame
Phase 3 as deepening an existing competency spine, not bolting one on.
