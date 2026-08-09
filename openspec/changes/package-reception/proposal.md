# SDD Proposal — Paquetería (package reception module)

- **Change:** `package-reception`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** propose
- **Artifact store:** openspec (this file) + engram `sdd/package-reception/proposal`
- **Date:** 2026-06-29
- **Source of truth:** the approved plan `~/.claude/plans/hola-estoy-buscabo-hacer-vast-lemur.md` (do not contradict it). Scope locked to **Completo (must + nice)**.

---

## 1. Intent

### Problem
Kivo7 has no way to manage parcels that arrive at the front desk for residents. Today the portería (security) receives packages with no record of who they are for, no notification to the resident, no pickup verification, and no chain of custody. There is **zero** package functionality in the codebase (verified by search).

### Why now
Parcel reception is a daily, high-friction front-desk operation in residential clusters and a frequent source of disputes ("I never got my package"). The domain maps almost 1:1 onto the existing **Access Control** module (a porter event tied to a unit, with photo + who captured it), so the platform can deliver a *complete* product feature — not a bare CRUD — by reusing molds that already exist (Access, NotifiesResidents, Setting/Configuration, Collections cron, Reports export).

### Success looks like
- Security registers an arriving package (single or bulk) in seconds: unit → carrier → photo → save.
- All active residents of that unit are notified automatically.
- Each package gets a stable, shareable **pickup code** (rendered as QR) visible ONLY to the owning resident; the guard scans it (never sees it) and the system shows that resident's pending packages.
- Guard hands over **1 or many packages in a single operation** — one signature/photo for the whole handover — and each package keeps its own custody record (who picked up, which guard, optional proxy).
- A daily cron reminds residents about uncollected packages and flags long-overdue ones for return.
- The cluster admin configures retention/notification/signature policies from an admin panel.
- Aging reports + a "pending pickup" dashboard widget give visibility.
- UI 100% in Spanish; all identifiers in English; tenant-isolated end to end.

---

## 2. Scope

### In scope (deliverable units — Completo: must + nice)
1. **Backend module `Package`** (Nwidart): `package` (tenant-scoped) + `package_status` (CENTRAL FSM) tables, models, `PackageService`, Orion CRUD + custom routes, FormRequests (array style).
2. **Intake**: single register (`register`) and bulk register (`batchRegister`), intake photo, `received_by`/`received_at`, auto status `received → ready`, arrival notification to active residents.
3. **Pickup & chain of custody**: per-package `pickup_code` (`unique(tenant_id, pickup_code)`, `$hidden` to everyone but the owning resident), `verifyPickupCode` (returns the resident's pending packages without exposing the code), `handover` of 1+ packages in one transaction with proxy fields + one signature/evidence for the whole handover, policy enforcement, `picked_up_*` custody records.
4. **Status lifecycle & corrections**: FSM `received → ready → picked_up`, plus `returned` and `incident` (lost/damaged/dispute); `reassignUnit` for mis-delivered parcels (audited, no extra column).
5. **Cluster policies (admin panel)** via the generic `Setting` store (slugs `package.*`) + Configuration module endpoints (`GET/PUT /config/package-settings`) + `PackageConfigSection` UI. **No** dedicated `package_setting` table.
6. **Retention cron** `package:process-retention` (daily, per tenant) — reminders after `reminder_after_days`, return suggestion after `return_after_days`.
7. **Tenant-scoped label catalogs**: `package_carrier` (seed MX: DHL, Estafeta, FedEx, UPS, Amazon, Mercado Libre, Correos de México, Otro) and `package_type`, admin-editable.
8. **Reporting**: pending-aging report (buckets 0-2 / 3-7 / 7+) with Excel export (`ReportExport`) + dashboard "pending pickup" widget.
9. **Notifications**: `package.received`, `package.reminder`, `package.picked_up` via `NotifiesResidents`, multi-channel, deep-link `click_action:'/my-packages'`.
10. **Frontend module `package`** (`frontend/src/modules/package/`): list + aging, fast intake + bulk, pickup modal (scan → multi-select → one signature), my-packages (code/QR, shareable), detail timeline, config section; menu/route/permission registration (regla 3), `IconBox`.

### Out of scope (deferred)
- Smart lockers / self-service kiosk.
- OCR of shipping labels.
- Resident pre-registration of expected deliveries.
- Vacation hold.

---

## 3. Capabilities (contract with sdd-spec)

> Research confirmed there is no existing `openspec/specs/` capability for packages. All capabilities below are **new**; each becomes a `spec.md` for sdd-spec to author. Names align with the delivery slices in §5.

### New capabilities
- `package-intake`: register single/bulk packages, intake custody (received_by, received_at, photo), status `received → ready`, arrival notification.
- `package-pickup`: pickup-code generation + resident-only visibility (QR), guard scan/verify, multi-package handover with proxy + one signature/evidence, per-package custody records, policy enforcement.
- `package-lifecycle`: `package_status` central FSM (received/ready/picked_up + returned/incident), return, incident flagging, unit reassignment (audited).
- `package-policies`: cluster-configurable `package.*` Setting slugs + Configuration endpoints + admin config UI.
- `package-retention`: daily per-tenant cron for reminders + auto-return suggestion.
- `package-catalogs`: tenant-scoped `package_carrier` + `package_type` label catalogs (seed + admin CRUD).
- `package-reporting`: aging report + Excel export + dashboard pending widget.

### Modified capabilities
- None at the spec level. New seeder rows (modules/permissions/system, central + tenant catalogs, `Setting` defaults) and `Configuration` endpoints are additive registration, not behavioral changes to existing capabilities.

---

## 4. Approach (intent/scope altitude — implementation belongs to sdd-design)

### Reuse seams (cite as the approach — do not reinvent)
- **Access Control** (`Modules/Access` — `Visitor`/`AccessLog` FK→unit, media, `qr_token` + `scanQr`, guard capture pattern) is the structural mold for `package`/intake/QR.
- **`NotifiesResidents`** concern for multi-channel notifications to active residents (`UnitUser` `end_date IS NULL`).
- **Collections cron** (`RefreshChargeStatusesCommand` + `registerCommandSchedules`, `Tenant::all()->run(...)`) is the mold for the retention command.
- **Reports framework** (`ReportColumns` / `ReportExport` Excel) for the aging report.
- **`Setting` + Configuration module + `CollectionConfigSection`** for cluster policies (generic key/value store — no new settings table).
- **Collaborator `batchImport`** for bulk intake.
- **Frontend** `modules/unit` + shared CRUD helpers (`resourceApi`, `EntityStore`, `useBaseList`, `useBaseCrud`), `ResponsiveDataTable`, `qrcode.react`.

### Key decisions & rationale
- **Recipient = `unit_id` REQUIRED + optional nominal recipient** (`recipient_user_id` and/or `recipient_name`). The unit is the source of truth; the nominal recipient is informational. Notifications go to **all active residents** of the unit, so no package is "stuck" on one person.
- **Stable shareable pickup code (not a 60s rotating token).** A per-package `pickup_code` `$hidden` from everyone but the owning resident elegantly solves **authorized-proxy pickup**: the resident shares the code/QR with a family member. The guard never reads the code — scanning only validates a match.
- **Handover of 1 OR MANY in one operation.** Scanning a resident's QR surfaces ALL their pending packages; the guard multi-selects and captures **one** signature/photo for the whole handover, while each package keeps its own custody record. This matches real front-desk behavior (one trip, several parcels).
- **`package_status` is a CENTRAL FSM catalog** (fixed UUID constants, `CentralCatalogModel`, `CatalogSeeder`) because the code branches on it. `notified` is a **timestamp**, not a state.
- **`package_carrier` / `package_type` are TENANT-SCOPED label catalogs** (`BelongsToTenant`, `unique(tenant_id, name)`, `TenantCatalogSeeder`) — free classification the admin edits, no branching logic.
- **Policies live in the generic `Setting` store**, not a bespoke table — consistent with how Collections settings work, and admin-editable via Configuration endpoints.

### Tenancy & naming correctness (hard requirements)
- Single shared DB; row-level isolation via `tenant_id` + `TenantScope`. `package` and the tenant catalogs use `BaseModel::columns($table, true)` + `BelongsToTenant`. `package_status` is central (no `tenant_id`). Residents see only their own units (`buildIndexFetchQuery` / `my-packages` override). The `unique(tenant_id, pickup_code)` constraint is per-tenant.
- **Naming (regla 6, HARD):** all identifiers English — module `Package`, table `package`, permission entity `package`, route `/package`. "Paquetería" only as UI text and the `modules.csv` `name` value.
- Every migration column carries `->comment()`; the `package` table closure comments the entity.

### Module registration impact (regla 3)
One sidebar module ⇒ full registration: `modules.csv` (`name="Paquetería"`), `permissions.csv` (`read/create/update/delete/restore/report.package`, catalog + config + report permissions), `module_system.csv` (tenant system + icon), `SystemTableSeeder` (entity/route `/package`/icon `IconBox`/visibility — hide central catalog), `RolePermissionTableSeeder` (Security intake+pickup; Resident/Renter read-own + verify; Administrator full + config + report; board read + report), `CatalogSeeder` (`package_status`), `TenantCatalogSeeder` (carrier/type), `Setting` defaults on `TenantCreated`, `module.json` + `modules_statuses.json`, and an `App.tsx` `<PermissionRoute permissionEntity="package">`. Then `php artisan migrate` + `db:seed --class=ResetPermissionTableSeeder`.

---

## 5. Delivery & sequencing (chained PRs — ask-on-risk)

This is a large, multi-file, full-stack feature that will exceed the 400-line review budget. Delivery = **chained/stacked PRs** sliced into autonomous work units (each with clear start, finish, verification, rollback):

1. **Backend spine** — scaffold, `package` + `package_status` migrations/models, Orion CRUD + `register` + notify, FormRequests, menu/permissions/seeders, central catalog.
2. **Pickup & custody** — `pickup_code` (generate + verify), multi-package handover with proxy + signature/evidence + policy enforcement, return, incident, reassign.
3. **Policies config** — `Setting` slugs, `/config/package-settings`, `PackageConfigSection`, disclaimer.
4. **Retention & reminders** — `package:process-retention` command + schedule + notifications.
5. **Reports & dashboard** — aging report + Excel export + pending widget.
6. **Tenant catalogs & extras** — `package_carrier` / `package_type` (+ admin UI), returned/incident polish.
7. **Frontend core** (may interleave per slice) — list/aging, intake + bulk, pickup modal, my-packages (code/QR), detail timeline.

Each slice gets a `/review-stack` pass (laravel-architecture + react-frontend + integration + tenancy); `@notifications-expert` is involved for the notification events.

---

## 6. Risks & open questions

| Risk / open question | Likelihood | Mitigation |
|---|---|---|
| **Pickup-code leak** (code visible to non-owners) | Med | `$hidden` on `pickup_code` for all but owning resident; guard endpoint validates match server-side and never returns the code; `tenancy-reviewer` pass. |
| **Cross-tenant leak** in resident/guard queries | Med | All queries through tenant-scoped Eloquent (`BelongsToTenant`), never `DB::` facade; `buildIndexFetchQuery` restricts residents to own units; mandatory tenancy review. |
| **Multi-package handover atomicity** (partial handover on failure) | Med | Single DB transaction in `handover`; on policy failure (missing signature/evidence) reject the whole operation. |
| **Notification fan-out** to all active residents | Low | Reuse `NotifiesResidents` (already battle-tested); involve `@notifications-expert`. |
| **Permission-row granularity** (6 rows per entity vs read/report only) | Low | Open → resolve in sdd-design, consistent with how tableros-contables decided. |
| **Large diff / review burden** | High | Chained PRs with work-unit commits + per-slice `/review-stack`. |
| **`package_status` central vs tenant misclassification** | Low | Decision rule applied: code branches on status → CENTRAL; verify against `CatalogSeeder` contract. |

---

## 7. Success criteria

- [ ] `php artisan migrate` clean; `package` + `package_status` + tenant catalogs created with correct `tenant_id` scoping.
- [ ] `ResetPermissionTableSeeder` seeds states/permissions/menu; `GET /api/menu` shows "Paquetería".
- [ ] Security can register (single + bulk); active residents of the unit get notified.
- [ ] Resident sees package + pickup code/QR in "Mis paquetes"; guard scan returns pending packages **without** exposing the code.
- [ ] Handover of 1+ packages in one operation records per-package custody + one signature/evidence; signature/evidence enforced only when policy requires it.
- [ ] Return / incident / unit reassignment work and are audited.
- [ ] Retention cron sends reminders after `reminder_after_days` and flags returns after `return_after_days`.
- [ ] Admin edits policies in "Configuración de Paquetería" (`Setting` slugs persisted, no new table).
- [ ] Aging report exports to Excel; dashboard widget shows pending count.
- [ ] Backend feature tests (host php, DB 127.0.0.1:5432) green incl. tenant isolation; `pnpm build` green; `/review-stack` per slice with no CRITICAL.

---

## 8. Next phases
- **sdd-spec** and **sdd-design** can run in **parallel** (both depend only on this proposal).
- sdd-design must close: permission-row granularity (6 rows vs read/report), exact media collections for intake photo vs handover evidence, `pickup_code` format + collision-retry strategy, and the per-slice PR chain boundaries.
