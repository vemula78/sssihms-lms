# SSSIHMS LMS

Learning Management System for SSSIHMS Whitefield — a WordPress plugin serving three
education tracks: clinical (medical/nursing/allied-health compliance & competency),
allied-health practical evaluation, and chaplaincy/spiritual formation.

- **SPEC.md** — functional specification (verification anchor)
- **ARCHITECTURE.md** — ADRs + data model
- **DECISIONS.md** — defaults chosen without user input
- **CONVENTIONS.md** — module-builder contract used during construction
- **sssihms-lms/** — the plugin (the only thing you deploy)
- **dev/** — local Docker WordPress for development/testing

## Production install (on-prem Ubuntu VM, existing WordPress)

1. Copy `sssihms-lms/` into `wp-content/plugins/` (or zip it and upload via
   Plugins → Add New → Upload).
2. Activate **SSSIHMS LMS**. Activation creates the database tables, the five LMS
   roles, the learner-portal pages, the private upload directory
   (`wp-content/uploads/sslms-private/`, deny-all), and the daily expiry-check cron.
3. Ensure pretty permalinks are enabled (Settings → Permalinks → Post name) — the
   REST API and portal pages assume it.
4. If the site runs nginx (not Apache), the `.htaccess` in `sslms-private/` is
   inert — add the equivalent nginx rule:
   `location ~* /wp-content/uploads/sslms-private/ { deny all; }`
5. Create users on the standard WP Users screen with the appropriate LMS role
   (LMS Student / Instructor / Supervisor-Preceptor / Evaluator / Mentor). Multiple
   roles per user are fine. Administrators automatically hold every LMS capability
   except reading private journal content (by design — DPDP data minimisation).
6. Under **SSSIHMS LMS → People**, fill learner profiles (staff ID, discipline,
   tracks) and create mentor/preceptor/evaluator relationships — sign-off and
   journal visibility scoping depend on these.
7. Outgoing email must work (expiry digests use `wp_mail`); the hospital SMTP
   plugin already configured for the site is sufficient.

Data is preserved on deactivation and on uninstall unless the option
`sslms_delete_on_uninstall` is set.

## Local development

```bash
cd dev
docker compose up -d           # WordPress on http://localhost:8890 (admin/admin)
docker compose exec cli wp plugin activate sssihms-lms
```

The plugin directory is bind-mounted, so edits are live. Project name is
`sslms-lms` (isolated volumes; does not touch other compose projects).

## Phase 2 (stubs only — not implemented)

`includes/interfaces/` defines SCORM/xAPI, Zoom/Teams, HR-sync and payment-gateway
interfaces plus the `sslms_register_integrations` filter. The admin screen
**SSSIHMS LMS → Integrations (Phase 2)** lists the slots. Implementations are a
separate future project.
