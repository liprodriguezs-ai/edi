# Design — complaints-module (Denuncias)

> Phase: sdd-design · Status: done · Next: sdd-spec (parallel) → sdd-tasks
> Change name: `complaints-module` · Project (engram): `residia-monorepo`
> Mirrored to engram topic `sdd/complaints-module/design`.
> Authority: `laravel-backend-architecture`, `react-frontend-architecture`, `stancl-tenancy`, `module-config` skills + live **Package** module.
> Binding inputs: proposal §"USER DECISIONS — CONFIRMED 2026-07-19" + engram decision #1684.

This is the HOW at architecture level. No implementation code beyond illustrative signatures.
Task breakdown is deferred to sdd-tasks.

---

## 1. Architecture approach

**Pattern:** copy the live **Package** module wholesale — it is the modern, in-repo reference
that already solves every cross-cutting concern the Kivo7 way (tenant-scoped model + Spatie media,
central FSM catalog with fixed UUID constants, tenant label catalogs, Orion controller with
`performStore` delegation + custom domain actions, `NotifiesResidents` deferred dispatch,
`module-config` per-tenant settings). Complaint is a **screaming/modular** Nwidart module
(`backend/Modules/Complaint/`) layered controller → service → model, with the service owning ALL
business rules (FSM transitions, folio, recipient routing, media) and the controller staying thin.

**Layering contract (unchanged from house style):**

| Layer | Responsibility | Files |
|---|---|---|
| Route | literal custom routes BEFORE `Orion::resource` (route-order gotcha) | `routes/api.php` |
| Controller | Orion CRUD surface + thin custom actions; auth gates; `performStore` swap | `ComplaintController`, catalog controllers |
| FormRequest | validation, per-module array-style rules (sibling consistency) | `Complaint{Create,Update}Request`, `ComplaintMessageRequest`, `ComplaintTransitionRequest`, `ComplaintAssignRequest` |
| Service | FSM, folio, exclusion routing, media, notifications, transactions | `ComplaintService`, `ComplaintMessageService` |
| Model | fillable, casts, relations, media collections; catalogs as their own models | `Complaint`, `ComplaintMessage`, `ComplaintStatus`, `ComplaintCategory`, `ComplaintPriority`, `ComplaintSequence` |

**Key deviation from Package:** Package's domain is guard/resident custody. Complaint's domain is a
**board/manager governance registry** with a confidentiality rule (exclude the denounced party).
That single rule (ADR-4) is the one piece with no direct Package precedent; everything else is a
structural copy.

**Non-goals (from proposal §3):** no resident self-filing UI (schema stays compatible), no Kanban,
no linked complaints, no SLA/escalation cron, no parallel audit table, no email channel v1, no
multi-assignee. The design must not *preclude* these — noted inline where relevant.

---

## 2. Component map & data flow

### 2.1 Register a complaint (board only)
```
POST /api/complaint  (Orion store, gated create.complaint = board roles)
  → ComplaintController::performStore
      → ComplaintService::register($validated, $reporter)
          DB::transaction:
            - stamp reporter_user_id = auth user
            - complaint_status_id = ComplaintStatus::OPEN
            - folio = D-YYYY-NNNNNN via ComplaintSequence (lockForUpdate)
            - save; attach `evidence` media if present
            - DB::afterCommit → IF setting `complaint.auto_notify_managers_on_create` is true
              (read via ComplaintSettings in ComplaintService::register, BEFORE invoking the
              trait) → notifyEvent('complaint_created', managers MINUS excluded). This flag is
              a module-config guard IN ADDITION to the per-tenant NotificationSetting gating
              that notifyEvent itself applies (§8); the other three events are not gated by it.
```

### 2.2 Transition status
```
POST /api/complaint/{complaintId}/transition   (gated update.complaint)
  → ComplaintController::transition
      → ComplaintService::changeStatus($complaint, $toStatusId, $actor)
          - assert actor is a configured manager AND not excluded for this complaint
          - assert transition allowed by FSM matrix (§5.1)
          - set status; stamp resolved_at when → resolved/dismissed
          - DB::afterCommit → notifyEvent('complaint_status_changed', reporter + assignee)
```

### 2.3 Assign owner
```
POST /api/complaint/{complaintId}/assign   (gated update.complaint)
  → ComplaintService::assign($complaint, $assigneeUserId, $actor)
      - assert assignee ∈ configured managers AND assignee NOT excluded (denounced-unit member)
      - set assigned_user_id
      - DB::afterCommit → notifyEvent('complaint_assigned', [assignee])
```

### 2.4 Post a follow-up message
```
POST /api/complaint/{complaintId}/message   (gated read.complaint + visibility scope)
  → ComplaintMessageService::postMessage($complaint, $body, $author, $attachment?)
      - assert author may see this complaint (visibility scope, §6)
      - create complaint_message; attach `attachment` media if present
      - DB::afterCommit → notifyEvent('complaint_message', other party)
```

### 2.5 Read (list / detail)
```
GET /api/complaint            (Orion index) → buildIndexFetchQuery applies visibleTo() scope (§6)
GET /api/complaint/{id}       (Orion show)  → buildShowFetchQuery applies visibleTo() scope
  includes: category, priority, status, reporter, assignee, against_unit, messages, media
```

Data flow invariants: (a) every write goes through a service inside a `DB::transaction`;
(b) every notification is deferred with `DB::afterCommit` so a dispatch failure never rolls back
the domain write; (c) tenant isolation is automatic via `BelongsToTenant` + `TenantScope` — never
raw `DB::` on tenant tables.

---

## 3. Data model

All tables tenant-scoped via `BaseModel::columns($table, true)` (adds `id` UUID, `tenant_id` NOT NULL
+ FK + index, timestamps, soft-delete, audit columns). Every own column carries `->comment()`; the
table comment is the first line inside the closure. Money: N/A (no money columns). Catalog split per
CLAUDE.md rule 8.

### 3.1 `complaint` (tenant-scoped, primary entity)

| Column | Type | Null | Notes / comment intent |
|---|---|---|---|
| `case_number` | string(14) | no | `D-YYYY-NNNNNN`; unique per tenant (partial unique WHERE deleted_at IS NULL) |
| `complaint_category_id` | FK `complaint_category` | no | tenant catalog; classification only (no branching) |
| `complaint_priority_id` | FK `complaint_priority` | no | tenant catalog; cosmetic triage (no branching) |
| `complaint_status_id` | FK `complaint_status` | no | central FSM; written ONLY by ComplaintService |
| `reporter_user_id` | FK `user` (nullOnDelete) | no | who filed; stamped from auth by service (NOT audit column) |
| `assigned_user_id` | FK `user` (nullOnDelete) | yes | single responsible owner (D2); set via assign action |
| `against_unit_id` | FK `unit` (nullOnDelete) | yes | **optional** denounced unit (drives auto-exclusion §6) |
| `against_description` | text | no | **always present** free-text subject/description of the denounced conduct/incident |
| `title` | string(150) | no | short human title for lists |
| `resolved_at` | dateTime | yes | stamped when status → resolved/dismissed; drives aging close |

Indexes: `complaint_category_id`, `complaint_priority_id`, `against_unit_id`, composite
`(complaint_status_id, created_at)` for the board list default sort. Partial unique
`(tenant_id, case_number) WHERE deleted_at IS NULL` (Package `pkg_pickup_code_tenant_unique` precedent).

Media collections on `Complaint` (implements `HasMedia`, `InteractsWithMedia`):
- `evidence` — photos/PDF captured at filing (`image/jpeg|png|webp`, `application/pdf`).

Relations: `category`, `priority`, `status`, `reporter` (user), `assignee` (user, nullable),
`against_unit` (unit, nullable), `messages` (hasMany, ordered by `created_at`).
Actor relations to `user` use straight `belongsTo` (reporter/assignee are tenant members, unlike
Package's guards which needed `withoutGlobalScope(UserTenantScope)` — complaint has no cross-tenant
actor, so no scope stripping).

> **Door left open (N1):** `reporter_user_id` is explicit (not inferred from `user_created_id`) so
> resident self-filing later is a permission+config change, not a re-migration. `against_user_id` /
> `against_employee_id` / `against_provider_id` are **deliberately absent** (user decision 1) and can
> be added as cheap nullable ALTERs if ever needed.

### 3.2 `complaint_message` (tenant-scoped, thread)

| Column | Type | Null | Notes |
|---|---|---|---|
| `complaint_id` | FK `complaint` (cascadeOnDelete) | no | parent thread |
| `user_id` | FK `user` (nullOnDelete) | no | author |
| `body` | text | no | message content |

Tenant-scoped (`BelongsToTenant`) for defense-in-depth even though always reached via a scoped
parent (house rule: every tenant table carries `tenant_id`). Media collection `attachment`
(`image/*` + `application/pdf`). Relation `author` (user), `complaint` (belongsTo).

### 3.3 `complaint_status` — CENTRAL FSM catalog

`CentralCatalogModel` (Cachable, no `tenant_id`), fixed UUID constants, seeded once by
`CatalogSeeder::seedComplaintStatuses()`. Mirrors `PackageStatus` exactly. Fillable `name, key, color`.

| const | key | name (ES) | color | terminal |
|---|---|---|---|---|
| `OPEN` | `open` | Registrada | `#3B82F6` | no |
| `IN_REVIEW` | `in_review` | En revisión | `#EAB308` | no |
| `RESOLVED` | `resolved` | Resuelta | `#22C55E` | yes |
| `DISMISSED` | `dismissed` | Desestimada | `#6B7280` | yes |

Read-only for tenant roles (exposed via `Orion::resource('complaint_status')->only(['index','show'])`).

### 3.4 `complaint_category`, `complaint_priority` — TENANT-SCOPED label catalogs

Both extend `TenantCatalogModel` (`BelongsToTenant` + Cachable), `unique(['tenant_id','name'])`,
seeded per tenant by `TenantCatalogSeeder` on `TenantCreated`. Fillable `name, is_active, order`
(+`color` for priority). No `key`/behavior columns — pure classification (R4 simplified: any
category may or may not carry a unit; no per-category subject validation).

TenantCatalogSeeder `CATALOGS` additions:
```
ComplaintCategory: Convivencia entre vecinos, Ruido, Mascotas, Estacionamiento,
                   Áreas comunes, Incidencia del clúster, Administración / Directiva, Otro
ComplaintPriority: Baja (#22C55E, 0), Media (#EAB308, 1), Alta (#EF4444, 2)
```

> Note: `PackageType` extends `BaseModel + BelongsToTenant` (not `TenantCatalogModel`). The skill's
> canonical class for tenant catalogs is `TenantCatalogModel` (Cachable, per-tenant cache prefix).
> **Decision:** use `TenantCatalogModel` for both new catalogs — it is the documented canon and gives
> per-tenant cache isolation; Package predates it. Confirm at review that the base class exists and is
> the current convention (assumption A2).

### 3.5 `complaint_sequence` — per-tenant per-year folio counter

Tenant-scoped, `year` (smallInteger) + `last_number` (bigInteger default 0). Direct copy of
`receipt_sequence` (Collections). Partial unique `(tenant_id, year) WHERE deleted_at IS NULL`.
Rationale in ADR-2.

---

## 4. Folio generation — ADR-2

**Decision:** adopt the **Collections `ReceiptSequence` counter-row** pattern — a dedicated
`complaint_sequence` table read/incremented under `lockForUpdate()` inside the create transaction,
formatting `sprintf('D-%04d-%06d', $year, $next)`.

Illustrative signature (in `ComplaintService`):
```php
private function nextCaseNumber(int $year): string; // 'D-2026-000042'
```
Implementation copies `PaymentService::nextReceiptSequence` / `createComplaintSequenceRow` verbatim
(row lock, savepoint on the create-row race, one-time backfill from `MAX(case_number)`).

**Alternatives considered:**
- *Old `TicketService` lockForUpdate + `MAX(case_number)` retry loop* — rejected: a `MAX` scan per insert
  costs more as the table grows and leaves folio **gaps** under contention. A formal complaint
  registry wants sequential, gapless-ish folios.
- *Package random `pickup_code` bounded-retry* — rejected: produces **non-sequential** codes.
  A folio must be predictable/sequential for a governance record; randomness is for a secret retrieval
  credential, a different requirement.

Trade-off accepted: one extra tiny table + a row lock on the create hot path (identical to payments,
which tolerate it fine at higher volume than complaints will ever see).

---

## 5. FSM & service layer

### 5.1 Transition matrix (enforced in `ComplaintService::changeStatus`)

| from → to | open | in_review | resolved | dismissed |
|---|:--:|:--:|:--:|:--:|
| **open** | — | ✅ | ✅ | ✅ |
| **in_review** | ✅ (reopen) | — | ✅ | ✅ |
| **resolved** | ✅ (reopen) | ✅ | — | — |
| **dismissed** | ✅ (reopen) | ✅ | — | — |

Terminal states (`resolved`, `dismissed`) can be **reopened** to `open`/`in_review` (governance
often needs to reopen a wrongly-closed complaint); resolved↔dismissed direct swap is disallowed
(must reopen first). `resolved_at` is stamped on entering a terminal state and **cleared on reopen**.
Invalid transitions throw `abort(422)`.

**Who may transition:** any user holding `update.complaint` (Spatie gate, §7) **who is a configured
manager (§7 config set) and is NOT excluded for this complaint (§6)**. The service enforces both the
manager-set membership and the exclusion — the Spatie permission is the coarse gate, config + exclusion
are the fine gate (ADR-5). Creation is a separate gate (`create.complaint`, board only).

### 5.2 Services

- `ComplaintService` (uses `NotifiesResidents`): `register`, `changeStatus`, `assign`, plus private
  `nextCaseNumber`, `resolveManagerRecipients` (§6), `ComplaintAccess::excludedUserIds` (§6). Setting reads memoized
  per request (Package `getSetting` memo pattern).
- `ComplaintMessageService` (uses `NotifiesResidents`): `postMessage` (create + media + notify other
  party). Split from the core service to keep each under the 500-line ESLint-equivalent budget and
  mirror old `TicketMessageService`.

Controller custom actions resolve the model by string id **inside** the method
(`Complaint::findOrFail($id)`) — NOT implicit route-model binding — so the lookup runs after
`InitializeTenancyFromUser` and stays tenant-scoped (Package route-order + tenancy gotcha, verbatim).

---

## 6. Confidential exclusion mechanism — ADR-4 (proposal R1/B4)

**Rule (user decision 4):** any **manager who is an active member of the denounced unit** (via
`unit_user` without `end_date`) is excluded from BOTH visibility and notifications for that specific
complaint. When `against_unit_id` is null (e.g. a free-text complaint against the administration),
**no automatic exclusion applies** — documented limitation; discretion stays with the board.

### 6.1 Recipient filtering (notifications)
In `ComplaintService`, before every `notifyEvent`, subtract the excluded set:
```php
private function excludedUserIdsFor(Complaint $c): array
    // [] when against_unit_id is null; else active unit_user user_ids of against_unit_id
private function resolveManagerRecipients(Complaint $c): array
    // configured manager role users (config manager_role_keys) MINUS excludedUserIdsFor($c)
```
`complaint_created` recipients = `resolveManagerRecipients($c)`. The denounced party is **never** a
recipient of any event (v1). `unitResidentUserIds()` from `NotifiesResidents` supplies the exclusion
source set; manager resolution reuses `userIdsWithRole()` (tenant-scoped Spatie role → user ids).

### 6.2 Visibility scope (list/detail/thread)
A query scope `visibleTo(User $u)` applied in `buildIndexFetchQuery` + `buildShowFetchQuery` (Package
`scopeToResidentUnitsIfNeeded` precedent), and re-checked in `ComplaintMessageService::postMessage`:

```php
public function scopeVisibleTo(Builder $q, User $u): Builder
    // hide complaints whose against_unit_id is a unit the user actively belongs to,
    // UNLESS the user is the reporter (a reporter always sees their own filing).
```
Concretely: `whereNotIn('against_unit_id', <caller active unit ids>)` OR
`reporter_user_id = $u->id`. Callers with `read.report_complaint` / `*` (super-admin) are still
subject to exclusion — the whole point is that even a Treasurer cannot see a complaint filed against
their own unit. Assignment (`assign`) applies the same exclusion: an excluded user cannot be set as
`assigned_user_id`.

### 6.3 Limits (must be surfaced at review)
- Exclusion is **unit-linkage based**, not identity based (there is no `against_user_id`). A denounced
  administrator who is *not* an active `unit_user` of the named unit is **not** auto-excluded.
- Free-text-only complaints (no unit) get **no** automatic exclusion by design.
- A manager linked to the unit loses visibility **entirely** for that complaint (cannot be assigned,
  notified, or read it) — intentional, but means such complaints route only to the remaining managers;
  if ALL managers are linked to the denounced unit, the complaint has no automated recipient (edge
  case; board handles out-of-band). Document as accepted v1 limitation.

---

## 7. Permission model — ADR-5 (proposal R5)

Two **distinct** gates, never conflated:

1. **Register gate — Spatie permission `create.complaint`.** New `complaint` permission entity
   (read/create/update/delete/restore/report) seeded via the CLAUDE.md rule-3 CSVs. `create.complaint`
   is granted **only to board roles** (President, Treasurer, Secretary, Vocal, Oversight Committee) in
   `RolePermissionTableSeeder` (v1 B2 "solo la mesa registra"). Controller sets
   `$keyForPermission = 'complaint'` so Orion auto-maps CRUD abilities.
2. **Manage/visibility gate — config-driven `complaint.manager_role_keys`.** A per-tenant setting
   (module-config §8) listing the role keys that *manage* complaints. The service resolves this set at
   runtime to filter notification recipients, assignability, and (combined with §6) visibility.

**How they interact without conflict:** Spatie is the **coarse endpoint gate** (can this token hit
`update.complaint`/`create.complaint` at all); `manager_role_keys` is the **fine per-tenant routing
set** (of those permitted, who actually manages/receives). The static seeder grants the *superset*
(board + admin) `update.complaint`; config narrows the effective managers. This keeps a fixed Spatie
grant (deploy-time) orthogonal to a tenant-editable manager list (runtime) — changing managers is a
config edit, never a permission re-seed.

`RolePermissionTableSeeder`: add `complaint.*` to `$permissionsOnlySuperAdmin`? **No** — complaints are
a tenant feature, so `create.complaint` goes to the board-role arrays and `read/update/...complaint`
enter account_owner/company_admin via the existing `whereNotIn` (they are NOT super-admin-only).
`report.complaint` (aka `read.report_complaint`) grants cluster-wide read to board read-only roles.

---

## 8. Notifications — ADR-6

Register **four** events in `CatalogSeeder::seedNotificationEvents()` with new fixed UUID constants on
`NotificationEvent`, `category = NotificationCategory::SYSTEM` (or a new `COMPLAINTS` category if the
enum permits — confirm at review, assumption A3), `audience = 'board'`, `supports_email = false`,
`default_channels = ['log','firebase_push']` (push + in-app only, N6):

| const / key | recipients | fallback copy (ES) |
|---|---|---|
| `COMPLAINT_CREATED` / `complaint_created` | `resolveManagerRecipients` (managers − excluded) | "Nueva denuncia registrada" |
| `COMPLAINT_ASSIGNED` / `complaint_assigned` | `[assigned_user_id]` (guarded not-excluded) | "Se te asignó una denuncia" |
| `COMPLAINT_STATUS_CHANGED` / `complaint_status_changed` | reporter + assignee | "Cambió el estado de una denuncia" |
| `COMPLAINT_MESSAGE` / `complaint_message` | the other party (reporter ↔ assignee/managers) | "Nuevo mensaje en una denuncia" |

Dispatch via `NotifiesResidents::notifyEvent($key, $recipients, $context, $fallbackTitle,
$fallbackBody, ['click_action' => '/complaint/'.$id])`, always inside `DB::afterCommit`. Per-tenant
`NotificationSetting` gating and the board-role audience routing are inherited from the trait; when no
per-tenant setting row exists the event falls back to its `default_channels` + `is_active` (same as the
Amenity events, which added catalog rows without per-tenant seeding). **The denounced party is never a
recipient of any event (v1).**

---

## 9. Media — ADR-7

Spatie media on both models using `App\Models\Media` (UUID PK, backend rule 15), attached in the
**service** inside the transaction (Package precedent + accepted orphaned-file trade-off compensated by
the scheduled `media-library:clean` job):
- `complaint.evidence` — filing evidence (photos/PDF).
- `complaint_message.attachment` — thread evidence.

A config toggle `complaint.requires_evidence` (default `false`) makes initial `evidence` mandatory —
enforced as a pre-flight `abort(422)` in `register` before opening the transaction (Package
`handover` policy pre-flight pattern).

---

## 10. module-config slice — ADR-8

Driven by the `module-config` interactive flow, delegated to `module-config-scaffolder`. Manifest
(confirm each param one-by-one in the dialog before scaffolding):

```yaml
module: Complaint
slug_namespace: complaint
settings:
  - key: manager_role_keys
    type: json
    default: ["president","treasurer","secretary","vocal","oversight_committee","administrator"]
    label: "Roles que gestionan denuncias"
    ui: multiselect            # board/admin role keys
  - key: reporter_role_keys
    type: json
    default: ["president","treasurer","secretary","vocal","oversight_committee"]
    label: "Roles que pueden registrar denuncias"
    ui: multiselect            # future-proofs B2 → resident self-filing
  - key: auto_notify_managers_on_create
    type: boolean
    default: true
    label: "Avisar a gestores al registrar una denuncia"
    ui: checkbox
  - key: requires_evidence
    type: boolean
    default: false
    label: "Exigir evidencia al registrar"
    ui: checkbox
  - key: resolution_days_target
    type: integer
    default: 15
    validation: "min:1|max:365"
    label: "Días objetivo de resolución"
    ui: number                 # aging DISPLAY only — no cron (D3)
guard_read_endpoint: false     # manager_role_keys is consumed server-side by ComplaintService;
                               # no non-admin runtime read endpoint needed
```

Scaffolder writes: `Setting` slugs `complaint.*`, `ConfigurationController::getComplaintSettings /
updateComplaintSettings` (flat API keys ↔ dotted slugs, `$request->all()` for dotted-key gotcha),
`GET/PUT config/complaint-settings` routes (permission `read/update.configuration`), **defaults seeded
in `TenantCreated`** via `Setting::firstOrCreate`, and `ComplaintConfigSection` (self-contained React
Query + `useSectionEdits`) registered as **one** entry in `configSections.tsx`:

```tsx
{ key: 'complaints', label: 'Denuncias', icon: <Megaphone|ShieldAlert>, title: 'Denuncias',
  description: '…', render: ({ canUpdate }) => <ComplaintConfigSection canUpdate={canUpdate} /> }
```

> Consistency note: `manager_role_keys`/`reporter_role_keys` are **role keys**, resolved to Spatie
> role slugs by the service. This is the config-vs-permission boundary from ADR-5 — config lists
> *which* roles manage; Spatie decides *whether* the endpoint is reachable.

---

## 11. Module registration checklist (CLAUDE.md strict rule 3)

Backend (CSV + seeders):
- `database/seeders/csv/modules.csv` — row `{uuid},Denuncias,{Group=Soporte or CRM}`.
- `database/seeders/csv/permissions.csv` — 6 rows (read/create/update/delete/restore/report) →
  `module_id`.
- `database/seeders/csv/module_system.csv` — assign module to a business system (NOT Core).
- `SystemTableSeeder::seedModulePermissionEntities()` — `'Complaint' => 'complaint'`.
- `SystemTableSeeder::seedModuleRoutes()` — `'Complaint' => '/complaint'`.
- `SystemTableSeeder::seedModuleIcons()` — `'Complaint' => 'ShieldAlert'` (verify name exists in
  `frontend/src/shared/utils/iconMap.ts`).
- `SystemTableSeeder::seedModuleVisibility()` — visible (not hidden).
- `RolePermissionTableSeeder` — `create.complaint` → board-role arrays; `read/update/delete/restore/
  report.complaint` reach account_owner/company_admin via `whereNotIn`; `report.complaint` → board
  read-only roles.
- `CatalogSeeder::seedComplaintStatuses()` (central) + `seedNotificationEvents()` +4 events.
- `TenantCatalogSeeder::CATALOGS` — `ComplaintCategory`, `ComplaintPriority` rows; module-config
  `complaint.*` Setting defaults (via `TenantCreated`).
- `modules_statuses.json` — `"Complaint": true`.
- Run `php artisan db:seed --class=ResetPermissionTableSeeder` after CSV edits.

Frontend (router): `App.tsx` — `<Route element={<PermissionRoute permissionEntity="complaint" />}>`
for `/complaint` (list) and `/complaint/:id` (detail); catalog admin routes for category/priority.

---

## 12. Frontend design (`frontend/src/modules/complaint/`)

Follows the 5-layer CRUD flow (schema → resource → store → hook → component) and the Package module
shape. React 19 + Vite + shadcn + Zustand + TanStack Query.

- **`schema.ts`** — `IComplaint`, `IComplaintMessage`, `IComplaintStatus` (extends `IEntity`),
  category/priority as `INamed`; Zod `complaintFormSchema` (title, category, priority,
  `against_unit_id` optional, `against_description` required, evidence file optional),
  `complaintMessageSchema`, `complaintTransitionSchema`, `complaintAssignSchema`. No `IXxxResponse`
  wrappers; computed fields optional on the entity interface. `type` imports only from this file.
- **`services/ComplaintResource.ts`** — `createCrudApi<IComplaint, ComplaintFormValues>` with
  `customMethods`: `transition(id, statusId)`, `assign(id, userId)`, `postMessage(id, FormData)`,
  `messages(id)`. `apiClient` only inside customMethods.
- **`store/complaintStore.ts`** — `createEntityStore<IComplaint>('complaint')` (factory only, never a
  manual store).
- **`ComplaintListPage.tsx`** — board-facing list. Filters: status / category / priority / assignee.
  Catalog options for the filter dropdowns loaded **lazily** via `useLazyCatalogs` +
  `MultiSelectFilter onFirstOpen` (infra-cost rule — NO `useCatalogs` at list scope). `useBaseList`.
- **`ComplaintDetailPage.tsx`** — Package `PackageDetailPage` lifecycle layout: header with folio +
  `ComplaintStatusBadge` (Package `PackageStatusBadge` clone) + priority chip + "X días abierta"
  aging chip (from `created_at` vs `resolved_at`, `resolution_days_target`); sections for denounced
  subject (unit + free text), evidence gallery, status timeline / transition actions (manager-gated),
  assignment control, and the message thread with attachment upload. Exports/actions use a **local**
  `isExporting`/`isSubmitting` button spinner, never the store's global `isLoading`
  (ux-local-busy-state rule).
- **`ComplaintForm.tsx`** — board-only create form (React Hook Form + Controller + Zod). Catalogs
  (category, priority, unit) loaded **on form open** (`useLazyCatalogs`), not at mount. `against_unit`
  optional; `against_description` required.
- **Catalog admin** — `category/` and `priority/` sub-folders each a `CatalogCrudModule`
  (`src/shared/components/catalog/CatalogCrudModule.tsx`) screen — simple label CRUD, no bespoke UI.
- **Skeletons** via `boneyard-js` `<Skeleton>`; toasts via `sileo` `{ toast }` from
  `@/shared/hooks/useToast`; dates via `displayFormatDate`.

**Spanish copy disambiguation (N8):** complaints use **"presentar/registrar denuncia"**,
**"Denuncias"**, **"denunciado"**; Chat's content moderation keeps **"reportar mensaje"**. The two
never share strings; the module label in the sidebar is **"Denuncias"**.

---

## 13. Architecture decisions (ADR summary)

| ADR | Decision | Rejected alternative |
|---|---|---|
| ADR-1 | Copy the **Package** module structure (Orion + Service + central FSM + tenant catalogs + `NotifiesResidents` + module-config) | Adapt old external `Ticket` module directly — rejected: all-central tables, no `tenant_id`, no comments, lawyer domain; zero portable migrations |
| ADR-2 | Sequential folio via **`complaint_sequence` counter row** (`lockForUpdate`), `D-YYYY-NNNNNN` | Old Ticket `MAX(case_number)`+retry (scan cost, gaps); Package random code (non-sequential) |
| ADR-3 | 4-state central FSM `open→in_review→resolved/dismissed`, reopen allowed, `resolved_at` cleared on reopen; no cron | 5th `in_progress` state / SLA cron — deferred (no concrete rule) |
| ADR-4 | Confidential exclusion by **unit-linkage** (`against_unit_id` active `unit_user` members) in both `visibleTo` scope and recipient resolution; null unit → no exclusion | Identity-based exclusion — impossible (no `against_user_id` by user decision); "notify everyone" — defeats the feature |
| ADR-5 | Two-gate model: Spatie `create.complaint` (board, coarse) + config `manager_role_keys` (runtime, fine) kept orthogonal | Single permission conflating register + manage — breaks configurable management (B3) and confidentiality routing |
| ADR-6 | 4 push+in-app `NotificationEvent`s, board audience, denounced party never a recipient v1 | Email channel / fewer merged events — deferred (N6), explicit events give clean per-event toggles |
| ADR-7 | Media on both `complaint.evidence` + `complaint_message.attachment`, `App\Models\Media`, attached in service | Evidence only on complaint — rejected, thread evidence is cheap and useful |
| ADR-8 | Per-tenant config via `module-config` scaffolder (5 settings), `manager_role_keys` server-consumed | Hardcoded manager set / per-module permission for the config screen — violates B3 + unified `read/update.configuration` |
| ADR-9 | Thread via custom `POST complaint/{id}/message` action + `messages` include (service owns routing) | Nested Orion `BelongsToPrimaryModel` resource — more surface, harder recipient routing |
| ADR-10 | `complaint_message` carries its own `tenant_id` (`BelongsToTenant`) | Rely on parent scoping only — rejected, house rule is every tenant table carries `tenant_id` |

---

## 14. Risks, assumptions & unresolved items

**Risks (architectural):**
- **R-A1 (from proposal R1) — exclusion completeness.** Unit-linkage exclusion cannot cover a
  denounced admin who is not an active `unit_user` of the named unit, nor free-text-only complaints
  (§6.3). Accepted v1 limitation; board retains manual discretion. Must be surfaced in sdd-verify and
  to the user.
- **R-A2 — all-managers-excluded edge.** If every configured manager is an active member of the
  denounced unit, `complaint_created` has no automated recipient. Accepted v1 edge; document in UI copy.
- **R-A3 — Spatie global roles vs tenant managers.** `manager_role_keys` resolves through
  `userIdsWithRole` which intersects Spatie (global) roles with tenant membership — correct per
  `NotifiesResidents`, but board roles are granted/revoked by `board_member` lifecycle; a stale role
  could momentarily widen recipients. Existing project-wide behavior, not new; note for review.
- **R-A4 — line budget.** Large module (>400 changed lines, `exception-ok`). Post-apply review starts
  a fresh bounded review; keep `ComplaintService`/`ComplaintMessageService` split under budget.

**Assumptions to confirm at review (design proceeded on these):**
- **A1** — `ResetPermissionTableSeeder` + the CSV seeders accept a new `complaint` entity with 6
  abilities exactly like Package (verify column names in the CSVs during apply).
- **A2** — `TenantCatalogModel` is the current canonical base for tenant catalogs (Package uses the
  older `BaseModel + BelongsToTenant`); design picks `TenantCatalogModel`.
- **A3** — `NotificationCategory` either has or can gain a `COMPLAINTS`/`SUPPORT` member; else reuse
  `SYSTEM`. Confirm in apply.
- **A4** — no billable add-on gating (Package uses `moduleEnabled:package`); complaints ship as a core
  functional module with the standard `auth:sanctum` + `initializeTenancy` middleware stack, no
  `moduleEnabled` guard — confirm with the user/billing whether Denuncias is a paid add-on (if so, add
  the `moduleEnabled:complaint` middleware and module_tenant wiring; not assumed here).

**Unresolved decisions handed to sdd-tasks / apply:**
- Exact icon name from `iconMap.ts` (`ShieldAlert` proposed).
- Whether `report.complaint` maps to a dedicated aging/report endpoint in v1 or is a read-scope only
  (proposal defers heavy reports; recommend read-scope only v1, no `ComplaintReportService`).
- The `module-config` dialog must still confirm the 5 settings one-by-one before scaffolding
  (D8 is a *proposed* manifest).
