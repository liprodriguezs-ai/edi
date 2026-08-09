# Proposal — complaints-module (Denuncias)

> Phase: sdd-propose · Status: done · Next: sdd-spec + sdd-design (parallel)
> Change name: `complaints-module` · Project (engram): `residia-monorepo`
> Mirrored to engram topic `sdd/complaints-module/proposal`.

---

## 1. Intent

Build a new Kivo7 functional module, **Complaints (Denuncias)**, that gives a residential
cluster one auditable place to register, track, assign, and resolve formal complaints.
The module covers three complaint scopes in a single entity:

1. **Neighbor-coexistence complaints** — a resident's conduct affecting others (noise, pets,
   parking, common-area misuse).
2. **Cluster incidences** — operational/physical problems with no single culpable person
   (broken gate, flooding, security lapse).
3. **Complaints against administration / board** — grievances aimed at the cluster's
   administrator or a `board_member` (President, Treasurer, Secretary, Vocal, Oversight
   Committee).

Today none of this exists in Kivo7. Complaints are handled off-platform (WhatsApp, verbal,
paper), so there is no history, no accountability, no status, and no audit trail of who
raised what and how it was resolved — exactly the governance gap a residential-management
SaaS should close. The old external CRM had a legal-advisory "Ticket" module whose Orion +
Service + status-catalog *skeleton* is a good starting shape (see `explore.md`), but its
lawyer/client domain, its central (non-tenant) tables, and its direct-push notifications are
all discarded.

**Success looks like:** a board member opens "Denuncias", registers a complaint (choosing its
category and, where relevant, the unit/person it is about), attaches evidence, and the
configured managing roles are notified, can assign an owner, exchange follow-up messages, and
move it through a clear status flow to a resolved/dismissed terminal state — all tenant-isolated
and fully audited.

---

## 2. Business context & rules (binding — set by the user)

These were decided by the user and are **not reopened** here:

- **B1 — One module, three scopes.** Neighbor coexistence + cluster incidences + complaints
  against admin/board live in one `complaint` entity, distinguished by a **tenant-scoped
  `complaint_category` catalog** (admin-editable labels).
- **B2 — Never anonymous.** Every complaint records its author. **v1 restriction: only board
  members (President, Treasurer, Secretary, Vocal, Oversight Committee) may REGISTER a
  complaint** ("por ahora"). Residents do not self-file in v1. The data model must NOT make
  later resident self-filing impossible.
- **B3 — Configurable management/visibility.** Which roles can see and manage complaints is
  **configurable per tenant** via the `module-config` flow.

Derived business rules the design must respect (surfaced by this proposal):

- **B4 — Confidential routing.** Because a complaint can target the administrator or a board
  member, recipient routing must be able to **exclude the denounced party** from the manager
  notification/visibility set for that specific complaint. Notifying the President that a
  complaint was filed against them defeats the feature. (See Risk R1.)
- **B5 — Tenant isolation.** All complaint data is tenant-scoped (single shared DB, row-level
  `tenant_id` + `TenantScope`), per `stancl-tenancy`.

---

## 3. Scope

### In scope (v1)

- New backend module `Complaint` (`backend/Modules/Complaint/`) generated via
  `nwidart/laravel-modules`, following the live **Package** module as the reference implementation.
- Core entity `complaint` (tenant-scoped) with: category, status, priority, reporter,
  structured/free-text subject ("denunciado"), assignment, description, evidence.
- Follow-up message thread `complaint_message` (tenant-scoped, secondary model) with optional
  attachments — the equivalent of the old `TicketMessage` / Package detail timeline.
- Central FSM status catalog `complaint_status` (fixed UUIDs, `CatalogSeeder`, read-only to
  tenants).
- Tenant-scoped label catalogs `complaint_category` and `complaint_priority` (seeded per tenant
  by `TenantCatalogSeeder` on `TenantCreated`).
- Custom domain actions (non-CRUD, Orion-style): change status, assign owner, post message —
  each notifying the right recipients via `NotifiesResidents::notifyEvent()`.
- Notification events registered in the `NotificationEvent` catalog and gated by per-tenant
  `NotificationSetting` (push + in-app), board/admin-centric routing.
- Per-tenant module configuration via the **`module-config`** flow (parameters proposed in §6).
- Frontend module `frontend/src/modules/complaint/` — **List + Detail** (Package pattern):
  board-facing list with filters, a detail page with status timeline, assignment, evidence and
  the message thread, plus a `ComplaintConfigSection` registered in `configSections.tsx`.
- Full module registration per `CLAUDE.md` strict rule 3 (modules/permissions/module_system CSV,
  `SystemTableSeeder` entity+route+icon, `RolePermissionTableSeeder`, `App.tsx` route,
  sidebar entry).

### Out of scope / non-goals (explicit)

- **N1 — Resident self-filing.** v1 is board-only registration (B2). The schema stays
  compatible with adding resident self-filing later (a permission + config change, not a
  re-migration), but the UI/flow for it is deferred.
- **N2 — Kanban board.** No live Kanban precedent exists in the 12 Kivo7 modules; reviving the
  old CRM Kanban is net-new work against shadcn + dnd-kit. Deferred; List + Detail ships v1.
- **N3 — Linked/related complaints** (`ticket_associated` equivalent). Deferred to v1.1.
- **N4 — SLA / time-based auto-escalation cron.** No auto-transition in v1 (see PROPOSED
  DEFAULT D3). A `resolution_days_target` config drives an aging *display* only. The Package
  retention cron remains the template if this is added later.
- **N5 — Parallel audit table** (old `HistoryTicket`). `BaseModel` already provides Laravel
  Auditing globally; no bespoke history model.
- **N6 — Email channel.** v1 notifications are push + in-app (board/admin recipients are active
  app users). Email can be layered later via the existing `SystemMailer` unification.
- **N7 — Multi-assignee.** One responsible owner per complaint in v1 (see D2).
- **N8 — Content-moderation overlap.** Chat's `chat_message_report` (offensive-message
  moderation) stays a separate concern; this module never touches it. UI copy must disambiguate
  "presentar denuncia" from Chat's "reportar mensaje".

---

## 4. Approach

### 4.1 Reuse strategy (old Ticket → new Complaint)

Per `explore.md`, the old external `Modules/Ticket` is reused as a **structural skeleton only**;
**zero migrations are copy-pasteable** (all central, no `tenant_id`, no `->comment()`). The live
**Package** module is the concrete implementation template, since it already solves every
cross-cutting concern the right way in this repo:

| Concern | Template to copy | Notes |
|---|---|---|
| Tenant-scoped model + media | `Package` model (`BelongsToTenant`, `InteractsWithMedia`, no-FK actor relations `->withoutGlobalScope(UserTenantScope::class)`) | Complaint mirrors this exactly |
| Central FSM catalog | `PackageStatus` (`CentralCatalogModel` + fixed UUID constants) | New `ComplaintStatus` with real state set (D3) |
| Tenant catalogs | `unit_status` / `PackageType` pattern (`TenantCatalogModel`, `unique(['tenant_id','name'])`) | `complaint_category`, `complaint_priority` |
| Migrations | `create_package_table` (table comment in closure, `BaseModel::columns($table, true)`, per-column `->comment()`, FK `->comment('FK to …')`) | Full re-migration, all tenant-scoped |
| Domain actions + Service | `PackageService` + custom controller actions (`handover`, `returnPackage`, `reassignUnit`) | `ComplaintService`: `changeStatus`, `assign`, `postMessage` |
| Notifications | `NotifiesResidents` + `notifyUsers`/`notifyEvent`, deferred via `DB::afterCommit` | Board/admin routing (B4) |
| Folio generation | `TicketService` `lockForUpdate` retry loop / Package's bounded-retry `persistWithUniquePickupCode` | Complaint folio `D-YYYY-NNNNNN` if adopted (see D1 note) |
| Detail UI + evidence + timeline | Package `PackageDetailPage` + old `TicketChat`/`TicketTimeline` | List + Detail (D6) |

### 4.2 Data model (shape — details resolved in sdd-design)

- `complaint` (tenant-scoped): `folio`, `complaint_category_id` (FK tenant catalog),
  `complaint_status_id` (FK central FSM, service-managed), `complaint_priority_id` (FK tenant
  catalog), `reporter_user_id` (nullable FK user, stamped from auth), `assigned_user_id`
  (nullable FK user), nullable structured subject FKs + free text (D1), `title`, `description`,
  timestamps for status transitions, evidence media collection.
- `complaint_message` (tenant-scoped, `BelongsToPrimaryModel` → `complaint`): `complaint_id`,
  `user_id`, `body`, attachment media collection.
- `complaint_status` (central, `CentralCatalogModel`, fixed UUIDs).
- `complaint_category`, `complaint_priority` (tenant-scoped, `TenantCatalogModel`).

### 4.3 Registration & config

- Module registration follows `CLAUDE.md` strict rule 3 end-to-end (CSV + seeders + frontend
  router + sidebar + permissions in `RolePermissionTableSeeder`).
- Per-tenant configuration is created through the **`module-config`** interactive flow, which
  hands a manifest to `module-config-scaffolder` (writes `Setting` slugs `complaint.*`,
  `ConfigurationController` get/update methods, `config/complaint-settings` routes, seeded
  defaults in `TenantCreated`, and the `ComplaintConfigSection` registered in `configSections`).

---

## 5. Proposed defaults for the open decisions (mark for user veto)

Each item below is a **PROPOSED DEFAULT** with rationale + the alternative. These need user
sign-off before sdd-design freezes the data model (they change table shape). See "Proposal
question round" at the end.

### D1 — Denounced party ("denunciado") shape
**PROPOSED DEFAULT:** discrete **nullable FK columns + free text**, mirroring the `Expense`
beneficiary pattern (nullable FKs, no polymorphism): `against_unit_id` (FK `unit`),
`against_user_id` (FK `user`, e.g. a board member or resident), and `against_description`
(free text for a general cluster incidence with no structured subject). The complaint's KIND
comes from `complaint_category_id`; the subject columns are all optional and the service
validates that the combination is coherent for the chosen category.
- *Why:* covers all three scopes without polymorphic complexity; matches the project's
  established "nullable FKs + selector" style (Expense) over morph tables; adding
  `against_employee_id` / `against_provider_id` later is a cheap ALTER.
- *v1 subject FKs:* `unit` + `user` + free text. **Defer** `employee`/`provider` FKs to a later
  slice (door left open).
- *Reporter:* explicit `reporter_user_id` stamped by the service from the authenticated user
  (not just relying on `user_created_id`) — decouples "who filed" from audit columns and
  survives future resident self-filing.
- *Alternative:* polymorphic `subject_type` + `subject_id`. Rejected for v1 (heavier, against
  house style, harder to whitelist in Orion includes).

### D2 — Assignment model
**PROPOSED DEFAULT:** a **single nullable `assigned_user_id` FK on `complaint`** (one
responsible owner), set via a custom `assign` action and restricted to the tenant's configured
managing roles (B3). Assigning notifies the assignee (`complaint_assigned`).
- *Why:* KISS; complaints have one owner-of-record; no v1 need for multi-assignee. The old
  `TicketAssignment` many-to-many is more than the domain requires.
- *Alternative:* `complaint_assignment` pivot (multi-assignee). Deferred (N7); a pivot can be
  added later without touching the core row.

### D3 — FSM status set + escalation
**PROPOSED DEFAULT:** central `complaint_status` with **four states**:
`open` (registrada) → `in_review` (en revisión) → `resolved` (resuelta) **/** `dismissed`
(desestimada). Terminal: `resolved`, `dismissed`. Fixed UUID constants, seeded once by
`CatalogSeeder`, transitions written only by `ComplaintService` in-transaction.
- **No SLA/cron in v1** (N4). A `complaint.resolution_days_target` setting drives an aging
  display ("X días abierta") without any auto-transition. The `ProcessPackageRetentionCommand`
  remains the template if escalation is later required.
- *Why:* four states express the real complaint lifecycle while staying minimal; deferring the
  cron avoids infra cost and premature policy.
- *Alternative:* add `in_progress` (5 states) or an SLA cron now. Deferred until there is a
  concrete business rule for auto-escalation.

### D4 — Priority
**PROPOSED DEFAULT:** **tenant-scoped `complaint_priority` catalog** seeded per tenant with
`low` / `medium` / `high` defaults (label + color), admin-editable.
- *Why:* priority is display/triage-only — it drives no code branch. Per the project decision
  rule (branch → central; cosmetic → tenant-scoped or drop) and HARD rule 8/14 (no hardcoded
  enums; enums = catalog tables), a cosmetic enum belongs in a tenant-scoped catalog, not a
  central FSM and not a raw column.
- *Alternative:* **drop priority for v1** (simplest) or model it central. Central is rejected
  (no branching); dropping is a viable lighter option if the user prefers.

### D5 — Evidence attachments
**PROPOSED DEFAULT:** Spatie media on **both** — an `evidence` collection on `complaint`
(photos/PDF at filing) and an `attachment` collection on `complaint_message` (evidence in the
follow-up thread). Uses `App\Models\Media` (UUID) per backend rule 15. A config toggle
`complaint.requires_evidence` (default `false`) can make initial evidence mandatory.
- *Why:* complaints are evidentiary by nature; mirrors Package (`photo` + `pickup_evidence`)
  and old `TicketMessage` media.
- *Alternative:* evidence only on the complaint, none on messages. Rejected — thread evidence
  is cheap (same `InteractsWithMedia` wiring) and materially useful.

### D6 — UI shape
**PROPOSED DEFAULT:** **List + Detail** (Package pattern). Board-facing List with filters
(status, category, priority, assignee) + a Detail page showing complaint info, denounced
subject, status timeline, assignment, evidence, and the message thread. Catalogs
(`complaint_category`, `complaint_priority`) as simple `CatalogCrudModule` screens.
- *Why:* exploration's recommendation; a live, proven pattern; no dnd-kit/Kanban net-new cost.
- *Alternative:* Kanban (N2). Deferred.

### D7 — Notification events
**PROPOSED DEFAULT:** register four events via `NotificationEvent` + `notifyEvent()`
(push + in-app, per-tenant `NotificationSetting`):
- `complaint_created` → configured managing roles (B3), **excluding the denounced party** (B4).
- `complaint_assigned` → the newly assigned owner.
- `complaint_status_changed` → reporter + assignee.
- `complaint_message` → the other party in the thread (reporter ↔ assignee/managers).
- *Why:* board/admin-centric routing fits B2 (only board files, board/admin manages);
  confidentiality of admin/board-targeted complaints is honored by B4 exclusion.
- *Alternative:* fold `complaint_status_changed`/resolved into fewer events. Kept explicit for
  clean per-event `NotificationSetting` toggles.

### D8 — `module-config` parameters (proposed manifest, confirmed in the module-config flow)
| slug | type | default | label (ES) | purpose |
|---|---|---|---|---|
| `complaint.manager_role_keys` | json | board role keys | Roles que gestionan denuncias | who sees/manages (B3) |
| `complaint.reporter_role_keys` | json | board role keys | Roles que pueden registrar denuncias | future-proofs B2 → resident self-filing |
| `complaint.auto_notify_managers_on_create` | boolean | `true` | Avisar a gestores al registrar | drives `complaint_created` |
| `complaint.requires_evidence` | boolean | `false` | Exigir evidencia al registrar | gates D5 |
| `complaint.resolution_days_target` | integer | `15` | Días objetivo de resolución | aging display (D3), no cron |
- *Note:* the actual parameter set is confirmed one-by-one inside the interactive `module-config`
  dialog before scaffolding; this is the proposed starting manifest.

### D9 — Naming
**PROPOSED DEFAULT:** English identifiers (HARD rule): `complaint`, `complaint_message`,
`complaint_status`, `complaint_category`, `complaint_priority`; folio prefix `D-YYYY-NNNNNN`
("Denuncia"). UI in Spanish: "Denuncias". Copy disambiguated from Chat's "reportar mensaje"
(content moderation) — complaints use "presentar/registrar denuncia".

---

## 6. Impact & risks

### Impact
- **New module, two submodules touched** (`backend/`, `frontend/`) + superproject registration
  files. No changes to existing modules' behavior; Collections' delinquency restrictions and
  other cross-cutting services are untouched.
- **Seeders:** `CatalogSeeder` (central `complaint_status`), `TenantCatalogSeeder` (per-tenant
  `complaint_category`, `complaint_priority`), `TenantCreated` (config defaults), plus the
  registration-checklist CSV/seeder edits (rule 3).
- **Permissions:** new `complaint` permission entity (read/create/update/delete/restore/report)
  wired into the correct role arrays.

### Risks / open questions
- **R1 (highest) — Confidential routing for admin/board-targeted complaints (B4).** Manager
  notification/visibility must exclude the denounced party. This is a real business rule that
  shapes the recipient-resolution logic and the visibility query; it must be settled in
  sdd-design, not left implicit.
- **R2 — Data-model lock depends on D1/D2/D3/D4.** These four change table shape; advancing to
  design/apply before the user vetoes/confirms them risks a re-migration.
- **R3 — Backend cost = full rewrite, not adaptation.** Budget every migration/model as net-new
  (old Ticket tables are non-portable). Frontend can lean on Package + old Ticket detail UI, but
  the old Kanban is absent from the tree and is not being revived.
- **R4 — `complaint_category` ↔ scope coherence.** Since one category catalog spans three very
  different scopes, the service must validate that the chosen subject FKs make sense for the
  category (e.g. a "convivencia" complaint expects `against_unit_id`/`against_user_id`; a
  general incidence may have none). Design must define this validation.
- **R5 — Only board registers (B2), but managing roles are configurable (B3).** The register
  gate (board roles) and the manage/visibility gate (configured roles) are two different sets;
  design must not conflate the `create.complaint` permission with the config-driven manager set.

### Size & delivery
- **Rough size:** large — ~4 migrations, ~5 models, 2–4 controllers, a service, request pairs,
  FSM/catalog seeding, notification events, ~9+ frontend files (List, Detail, message thread,
  form, two catalog screens) plus the config section and full registration. **Will exceed 400
  changed lines.**
- **Delivery strategy:** `exception-ok` (single large feature; splitting would fragment a
  cohesive module).
- **Chain strategy:** N/A — single backend+frontend submodule pair, no cross-repo dependency
  chain.

---

## Proposal question round (needs user review before sdd-design)

The nine PROPOSED DEFAULTS above are the review surface. The smallest useful set of product
questions for the user to confirm or veto:

1. **Denounced subject (D1):** OK to model the "denunciado" as nullable `unit` + `user` FKs +
   free text (Expense-style), deferring employee/provider FKs? Or do you need
   employee/provider as first-class subjects in v1?
2. **Priority (D4):** keep a tenant-editable `low/medium/high` priority catalog, or **drop
   priority** entirely for v1?
3. **Status set (D3):** are the four states `open → in_review → resolved / dismissed` right, or
   do you want an explicit `in_progress`? Confirm **no auto-escalation cron** in v1.
4. **Confidential routing (R1/B4):** confirm that when a complaint targets the administrator or
   a board member, that person must be **excluded** from the manager notifications/visibility.
5. **module-config params (D8):** is the proposed parameter set complete, or should the
   `module-config` dialog add/remove any (e.g. a "denuncias visibles para el denunciante"
   toggle)?

If the user is unavailable, sdd-design proceeds on the PROPOSED DEFAULTS as written and flags
D1/D3/D4 + R1 as assumptions to be confirmed at review.

---

## USER DECISIONS — CONFIRMED 2026-07-19 (binding for sdd-spec / sdd-design)

The user reviewed the proposal question round. These answers OVERRIDE the proposed defaults
where they differ:

1. **D1 denounced subject — AMENDED:** free text ALWAYS (`against_description`) + **optional
   unit** (`against_unit_id` nullable FK). There is **NO `against_user_id`** and no
   employee/provider FKs in v1. ("mas bien es texto libre con unidad (opcional)")
2. **D3 status FSM — CONFIRMED as proposed:** central catalog, 4 states
   `open → in_review → resolved / dismissed`, fixed UUIDs, **no SLA/escalation cron in v1**
   (`resolution_days_target` is display/aging only).
3. **D4 priority — CONFIRMED as proposed:** tenant-scoped `complaint_priority` catalog
   (low/medium/high labels, cosmetic, no code branching).
4. **R1/B4 confidential routing — CONFIRMED (automatic exclusion), derived rule given D1:**
   since the denounced party is identified only by the optional `against_unit_id`, the
   exclusion rule is: **any manager linked to the denounced unit via `unit_user` is excluded
   from visibility AND notifications for that complaint.** When no unit is selected (e.g.
   complaint against the administration in free text), automatic exclusion does not apply —
   documented limitation, discretion stays with the board. Design must implement the
   unit-linkage exclusion in both query scope and notification recipient resolution.
5. **R4 category↔subject validation — SIMPLIFIED by D1 amendment:** with only
   `against_unit_id` (optional) + free text, no per-category FK validation is needed; any
   category may or may not carry a unit.

6. **Billing (design R-A4) — CONFIRMED 2026-07-19:** Denuncias is a CORE functional module,
   included for all clusters — NO `moduleEnabled` paywall guard, not part of the pending
   billing add-ons catalog. (Can be converted to add-on later if pricing changes.)

7. **Reopen terminal complaints — CONFIRMED 2026-07-19 (design-gate finding resolved):**
   manager-role users MAY reopen `resolved`/`dismissed` complaints (→ `open` or `in_review`,
   clearing `resolved_at`). spec.md "Terminal state is immutable" scenario was AMENDED
   accordingly; design.md transition matrix (§5.1/ADR-3) stands as designed.

Earlier session-level decisions (from the first product round, also binding): single module
covering the three scopes with tenant-scoped `complaint_category`; never anonymous; v1
registration restricted to board-member roles (design must not preclude resident self-filing
later); manage/visibility roles configurable per tenant via module-config (D8 set stands,
pending nothing).
