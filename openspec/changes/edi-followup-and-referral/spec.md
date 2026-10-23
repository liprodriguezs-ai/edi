# Spec: EDI Follow-Up and Referral

Change: `edi-followup-and-referral`
Capabilities: `follow-up-and-referral` (new), `screening-application` (modified — delta at the end)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> Color constants come from the central `result_color` catalog (`ResultColor::GREEN|YELLOW|RED`).
> "Completing screening" = `ScreeningLifecycleService::complete()` succeeding (`scored → completed`).
> "Open follow-up" = `follow_up_status` in {`pending`, `in_progress`, `overdue`}.
> Error envelopes: `{ "message": string (Spanish UI), "code": UPPER_SNAKE constant, ...context }`.
> On archive, REQ-FUP-* merge into `openspec/specs/follow-up-and-referral/spec.md` and REQ-SCR-016 into `openspec/specs/screening-application/spec.md`.

---

## ADDED Requirements — capability `follow-up-and-referral`

### REQ-FUP-001 — Persistence model and catalogs

The system SHALL create 2 tenant-scoped tables (`BaseModel::columns($table, true)` + `BelongsToTenant`): `follow_up` and `referral`; 2 CENTRAL catalogs (`BaseModel::columns($table, false)`, fixed UUID model constants, seeded by `CatalogSeeder`, read-only for tenant roles): `follow_up_type` (keys `reassess_3_months`, `specialist_referral`, `confirmatory_test`) and `follow_up_status` (keys `pending`, `in_progress`, `completed`, `overdue`, `cancelled`); and 1 TENANT-SCOPED catalog `referral_specialty` (`unique(['tenant_id','name'])`, seeded per tenant by `TenantCatalogSeeder` with: Neurología pediátrica, Audiología, Oftalmología, Terapia de lenguaje, Terapia física, Psicología — admin-editable). `follow_up` MUST carry the composite index `(tenant_id, follow_up_status_id, due_date)` and the partial unique `(screening_id, follow_up_type_id) WHERE deleted_at IS NULL`. Every own migration column MUST carry `->comment()`; every table comments itself inside the closure. Exact columns per `design.md` §3.

#### Scenario: catalogs split central vs tenant
- WHEN `migrate:fresh --seed` runs and a tenant is created
- THEN `follow_up_type` has exactly 3 rows and `follow_up_status` exactly 5 rows with no `tenant_id`, and the tenant has its own 6 `referral_specialty` rows editable through the catalog CRUD.

#### Scenario: central catalogs frozen for tenant roles
- WHEN a tenant-role user calls create/update/delete on `follow_up_type` or `follow_up_status`
- THEN the request is rejected by permissions (403).

### REQ-FUP-002 — Follow-up creation is event-driven by result color

WHEN a screening completes, the system SHALL create follow-ups for the screening's child according to the authoritative global color — via the `ScreeningCompleted` event listener, never by polling:

| Result | Follow-ups created | due_date (from `visit.visit_date`) |
|---|---|---|
| green | none | — |
| yellow (first or previous ≠ yellow) | `reassess_3_months` | + 3 months |
| yellow AND `previous_result_color_id` = yellow (recurrence) | `reassess_3_months` + `specialist_referral` | + 3 months / + 30 days |
| red | `specialist_referral` + `confirmatory_test` | + 30 days each |

Each created row MUST reference the originating `screening_id` and `child_id`, start with a status derived from `due_date` per REQ-FUP-005, and (for `specialist_referral`) leave `referral_specialty_id` NULL for staff to fill. Follow-up creation MUST be atomic with the completion transaction: if the listener fails, the screening does NOT complete.

#### Scenario: red creates the referral pair
- GIVEN a scored screening whose global color is red
- WHEN it completes
- THEN exactly 2 follow-ups exist for the screening: `specialist_referral` and `confirmatory_test`, both `pending`, due 30 days after the visit date.

#### Scenario: first yellow creates only the reassessment
- GIVEN a scored yellow screening with `previous_result_color_id` NULL or green
- WHEN it completes
- THEN exactly 1 `reassess_3_months` follow-up exists, due 3 months after the visit date.

#### Scenario: recurrent yellow escalates to referral
- GIVEN a scored yellow screening whose frozen `previous_result_color_id` is yellow
- WHEN it completes
- THEN both a `reassess_3_months` and a `specialist_referral` follow-up exist.

#### Scenario: green creates nothing
- WHEN a green screening completes
- THEN zero follow-up rows reference it (open reassessments of the child still close per REQ-FUP-004).

### REQ-FUP-003 — Idempotent listener

Completing MUST create at most one follow-up per `(screening_id, follow_up_type_id)`, enforced BOTH by the partial unique index and by create-if-missing semantics in the listener. Replaying `ScreeningCompleted` for an already-processed screening (listener retry, manual re-dispatch) MUST NOT create, duplicate, or re-close anything. A manual `POST` duplicating an existing `(screening, type)` pair MUST return 422 `FOLLOW_UP_DUPLICATE`.

#### Scenario: double dispatch is a no-op
- GIVEN a completed red screening with its 2 follow-ups
- WHEN `ScreeningCompleted` is dispatched again for the same screening
- THEN the follow-up count and every row's attributes are unchanged.

### REQ-FUP-004 — Reassessment auto-close by a later screening

WHEN a screening S of child C completes (any color), the listener SHALL FIRST close every open `reassess_3_months` follow-up of C whose originating screening has `visit.visit_date <=` S's visit date and whose `screening_id ≠ S.id`: set status `completed`, `closed_by_screening_id = S.id`, `completed_at = now()`. Only THEN are S's own follow-ups created (close-then-create ordering). `specialist_referral` and `confirmatory_test` follow-ups MUST NOT auto-close — they close manually (REQ-FUP-006). Auto-close and creation share the completion transaction.

#### Scenario: the 3-month loop closes on reapplication
- GIVEN child C with an open `reassess_3_months` follow-up from a yellow screening
- WHEN a later green screening of C completes
- THEN that follow-up is `completed` with `closed_by_screening_id` = the green screening, and no new follow-up is created.

#### Scenario: a backfilled older screening does not close
- GIVEN an open reassessment originated by a visit dated 2026-06-01
- WHEN a screening of the same child for a visit dated 2026-05-01 completes late
- THEN the reassessment remains open.

#### Scenario: referrals never auto-close
- GIVEN an open `specialist_referral` follow-up of C
- WHEN any screening of C completes
- THEN it remains open.

### REQ-FUP-005 — `overdue` is derived, never written by anyone

`overdue` SHALL be a pure function of `due_date` vs today over the base state `pending`: no endpoint, request field, or manual transition may set it. It materializes in `follow_up_status_id` two ways: (a) on every service write (create/update), the status is derived — `pending` when `due_date >= today`, `overdue` when past; (b) the artisan command `follow-up:refresh-overdue`, scheduled daily, converges both directions per tenant: `pending` with `due_date < today` → `overdue`, and `overdue` with `due_date >= today` (after a reschedule) → `pending`. The command MUST be idempotent (an immediate second run changes zero rows), MUST iterate tenants and touch only the current tenant's rows, and MUST NOT touch `in_progress`, `completed`, or `cancelled` rows. Rationale for materializing instead of computing on read: `design.md` D7.

#### Scenario: daily command flips both directions
- GIVEN tenant T with a `pending` follow-up due yesterday and an `overdue` one rescheduled to next week
- WHEN `follow-up:refresh-overdue` runs
- THEN the first is `overdue`, the second is `pending`, and a second immediate run reports zero changes.

#### Scenario: manual states are never touched
- GIVEN an `in_progress` follow-up due yesterday and a `cancelled` one due yesterday
- WHEN the command runs
- THEN both keep their status.

### REQ-FUP-006 — Guarded writes and manual lifecycle

`FollowUp::$fillable` MUST exclude `follow_up_status_id`, `closed_by_screening_id`, and `completed_at` — services assign them via `forceFill`. Manual transitions are explicit endpoints: `POST /{id}/start` (`pending|overdue → in_progress`), `POST /{id}/complete` (open → `completed`, sets `completed_at`, leaves `closed_by_screening_id` NULL to distinguish manual close), `POST /{id}/cancel` with mandatory `reason` (open → `cancelled`, stored in `cancellation_reason`). `completed` and `cancelled` are terminal: any transition or update on them returns 409 `FOLLOW_UP_FROZEN`. Orion `update` allows only `due_date`, `notes`, `referral_specialty_id` while open. Orion `destroy` is NOT exposed. Manual `store` requires an existing `completed`, non-invalidated screening of the same child (else 422 `SCREENING_NOT_ELIGIBLE`).

#### Scenario: forged status is ignored
- WHEN a client sends `follow_up_status_id`, `closed_by_screening_id`, or `completed_at` in create/update payloads
- THEN every injected field is ignored and the stored values are service-derived.

#### Scenario: terminal freeze
- GIVEN a `cancelled` follow-up
- WHEN any transition or update is attempted
- THEN the response is 409 `FOLLOW_UP_FROZEN`.

### REQ-FUP-007 — Referral record

`POST /api/referral` SHALL create a referral bound to a `follow_up` of type `specialist_referral` — any other type returns 422 `FOLLOW_UP_TYPE_MISMATCH`. Fields: `referral_specialty_id` (tenant catalog, required), `referred_at` (date, required), `institution` (nullable), `outcome_notes` (nullable). Multiple referrals per follow-up are allowed (e.g., neurology AND audiology). Creating a referral on a `pending` or `overdue` follow-up SHALL move it to `in_progress`; completion of the follow-up stays manual. Referrals on a terminal follow-up return 409 `FOLLOW_UP_FROZEN`.

#### Scenario: type gate
- WHEN a referral is created against a `reassess_3_months` follow-up
- THEN the response is 422 `FOLLOW_UP_TYPE_MISMATCH` and no row is persisted.

#### Scenario: referral marks progress
- GIVEN a `pending` `specialist_referral` follow-up
- WHEN a referral is created
- THEN the follow-up is `in_progress` and the referral row exists.

### REQ-FUP-008 — Referral notification through the existing engine

Creating a referral SHALL dispatch the notification event `follow_up.referral_created` via `DispatchesNotifications::notifyEvent` — reusing the existing engine and channels, creating NO new channel. The event MUST be registered as a `NotificationEvent` catalog row (fixed UUID constant, `CatalogSeeder::seedNotificationEvents()`) with `default_channels = ['log','firebase_push']`, `supports_email = false` (v1). Recipients: users holding the `administrator` role within the tenant (`userIdsWithRole`). Per-tenant `NotificationSetting` MUST be honored: an organization that deactivates the event gets nothing recorded or dispatched. Spanish fallback title/body carry child name and specialty; template row is optional (engine falls back).

#### Scenario: dispatched with defaults
- GIVEN a tenant with no `notification_setting` row for the event
- WHEN a referral is created
- THEN administrators of that tenant receive the in-app notification (and push per default channels) after commit.

#### Scenario: tenant opt-out suppresses
- GIVEN the tenant deactivated `follow_up.referral_created`
- WHEN a referral is created
- THEN no in-app row is recorded and nothing is dispatched.

### REQ-FUP-009 — Work inbox UI and module registration

The frontend SHALL ship `frontend/src/modules/followUp/` using the shared scaffolding (`createEntityStore`, `createCrudApi`, `useBaseList`, `useBaseCrud` — no custom-logic screen): `FollowUpListPage` filterable by status (lazy catalog load on first open) and by due window (overdue / due soon), default-sorted by `due_date` asc; `FollowUpDetailPage` with the follow-up data, transitions, and its referrals (create modal loads catalogs on open); a `referral_specialty` catalog page via `createSimpleCatalogPage`. Registration MUST complete the full checklist: `modules.csv` (3 rows: Seguimientos, Referencias — hidden, Especialidades de Referencia), `permissions.csv` (18 rows), `module_system.csv`, the `SystemTableSeeder` maps (entities/routes/icons/visibility), `RolePermissionTableSeeder` (18 permissions; central catalog entities appended to `$centralCatalogs`), `AngularController::$mapperSelects` + `useCatalogs.ts::CatalogType` (`FollowUpType`/`FollowUpStatus`/`ReferralSpecialty`), and `App.tsx` routes under `PermissionRoute`. UI copy in Spanish; zero `any`; no eager catalog loading.

#### Scenario: inbox answers "what do I still have to chase"
- WHEN the user filters the list by status `overdue`
- THEN the request filters server-side on the indexed status column and renders due dates via `displayFormatDate`.

#### Scenario: registration is complete
- WHEN seeders run and an `administrator` logs in
- THEN `GET /api/menu` shows Seguimientos and Especialidades de Referencia (not Referencias), and a user without `read.follow_up` receives 403 on the endpoints.

### REQ-FUP-010 — Tenant isolation

All `follow_up`, `referral`, and `referral_specialty` rows SHALL be tenant-scoped. Cross-tenant reads and mutations MUST 404 under `TenantScope`; the listener derives tenant context from the completing screening's tenant; the daily command initializes each tenant before updating. A foreign `screening_id`, `child_id`, `follow_up_id`, or `referral_specialty_id` in any payload MUST be rejected by tenant-scoped `exists` validation.

#### Scenario: cross-tenant follow-up invisible
- GIVEN a follow-up of tenant A
- WHEN a tenant-B user requests it by id
- THEN the response is 404.

---

## ADDED Requirements — capability `screening-application` (delta)

### REQ-SCR-016 — Completion emits `ScreeningCompleted`

`ScreeningLifecycleService::complete()` SHALL dispatch a `Modules\Screening\Events\ScreeningCompleted` domain event carrying the completed screening, inside the completion transaction, after the status flip to `completed`. The event fires at most once per screening through the API (the existing frozen guard rejects a second `complete`). `rescore`, `invalidate`, `score`, and answer mutations MUST NOT emit it. The scoring engine (`app/Services/Scoring/**`, `RuleSetLoading/**`) remains untouched.

#### Scenario: emitted exactly on complete
- WHEN a scored screening completes
- THEN `ScreeningCompleted` is dispatched once with that screening; and WHEN the same screening is rescored or another is invalidated, no `ScreeningCompleted` is dispatched.
