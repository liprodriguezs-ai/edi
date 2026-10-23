# Design: EDI Follow-Up and Referral

Change: `edi-followup-and-referral`

## 1. Context

`Modules/Screening` is delivered: `ScreeningLifecycleService::complete()` flips `scored → completed` inside a `lockForUpdate` transaction and currently emits nothing. The screening header carries everything the conduct needs: `result_color_id`, frozen `previous_result_color_id` (screening design D16), `child_id`, `visit_id`, `situation_id`. The notification engine is in place and verified: `DispatchesNotifications::notifyEvent()` resolves per-tenant `NotificationSetting` (falls back to the event's `default_channels` when no row exists; unknown keys are suppressed with a warning), renders `notification_template.{key}` with literal fallback copy, and defers via `DB::afterCommit`. `TenantCatalogSeeder::CATALOGS` already anticipates "especialidades de referencia" in its docblock. The scoring engine is FROZEN — nothing here touches `app/Services/Scoring/**` or `RuleSetLoading/**`. House patterns from Clinic/Screening apply: tenant tables via `BaseModel::columns($table, true)`, no DB FKs (`uuid()->index()`), partial uniques via `DB::statement`, fixed-UUID central catalogs.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Trigger is a domain event, listener is SYNCHRONOUS inside the completion transaction: `complete()` dispatches `Modules\Screening\Events\ScreeningCompleted($screening)` after the status flip; `Modules\FollowUp\Listeners\CreateFollowUpsFromScreening` (registered in the FollowUp provider) closes and creates in the same transaction | No polling (mandate). Atomicity: a completed screening ALWAYS has its conduct or nothing commits — the product's core promise cannot half-exist. Sync avoids queue/tenancy serialization plumbing; the listener is a handful of guarded writes, so latency is negligible. Notifications inside it stay safe (`notifyEvent` → `DB::afterCommit`). Tradeoff accepted: a listener bug blocks completion — preferred over silent conduct loss, and the test matrix covers every branch. Future consumers (Stimulation, Reports) can attach their own queued listeners without touching Screening |
| D2 | Idempotency is DB-enforced: partial unique `(screening_id, follow_up_type_id) WHERE deleted_at IS NULL` + `firstOrCreate` semantics in the listener | `complete()` already fires at most once per screening through the API (frozen guard), but listener retries/manual re-dispatch must be harmless. The unique is the belt-and-braces layer that survives any caller; the same index also blocks manual duplicates (422 `FOLLOW_UP_DUPLICATE`) |
| D3 | Color → conduct mapping (REQ-FUP-002) branches on `screening.result_color_id` with recurrence = current yellow AND frozen `previous_result_color_id` = yellow | The conduct table is the clinical contract (green: sessions only; yellow: reassess; recurrent yellow: also refer; red: refer + Battelle tracking). `previous_result_color_id` is frozen at first authoritative score upstream (D16), so recurrence is deterministic across rescores and immune to later sibling screenings |
| D4 | Due dates anchor on `visit.visit_date` (the clinical application date), not `completed_at`: reassess = +3 months; specialist_referral and confirmatory_test = +30 days | "Reapply in 3 months" counts from when the test was applied — completion may lag capture by days. 30 days for referral/confirmatory is a working default (open Q1); a backfilled old visit can therefore create an already-overdue follow-up, which D7's write-time derivation handles honestly |
| D5 | Auto-close (REQ-FUP-004): close-then-create ordering; only `reassess_3_months` closes; guard `origin.visit_date <= completing.visit_date` and `screening_id ≠ completing.id`; sets `completed_at` + `closed_by_screening_id` | A reapplication must first close the loop it answers, then open its own (a second yellow both closes the old window and starts a new one). The visit-date guard keeps a late-captured OLDER test from "answering" a follow-up it predates. `closed_by_screening_id` NULL vs set distinguishes manual from automatic closure without another column |
| D6 | Catalogs: `follow_up_type` (UUIDs `ed400001-…0001..3`) and `follow_up_status` (`ed400002-…0001..5`) are CENTRAL — code branches on both (creation matrix, auto-close, overdue FSM). `referral_specialty` is TENANT-SCOPED via `TenantCatalogSeeder` (6 seeded names, admin-editable, no `key` column — no system rows) | Hard rule 4's decision test applied verbatim. Specialty rows carry zero behavior — pure classification the organization tailors (a rural center may add "Nutrición") |
| D7 | `overdue` MATERIALIZES in `follow_up_status_id`: (a) derived at every service write; (b) converged daily by `follow-up:refresh-overdue` — per tenant, both directions (`pending → overdue` past due; `overdue → pending` after reschedule), never touching `in_progress`/`completed`/`cancelled`. Scheduled in `routes/console.php` (`->dailyAt('00:15')`) | Chosen over compute-on-read because: (1) the mandated inbox index `(tenant_id, follow_up_status_id, due_date)` only pays off if status is a real column value — the inbox filter and future reports hit it directly; (2) on-read would force every consumer (Orion filters, selects, reports, dashboard counts, future reminder notifications) to re-implement the `pending AND due_date < today` predicate, and any miss shows stale truth silently; (3) it mirrors the proven daily status-cron pattern of the predecessor system. The command is convergent — status is a pure function of (base state, due_date, today) — so reruns are no-ops and crashes need no cleanup. Staleness bound ≤ 1 day is acceptable: `due_date` has date granularity. "Today" uses the app timezone (America/Mexico_City market, open Q5) |
| D8 | Notification: ONE event `follow_up.referral_created` (`NotificationEvent::REFERRAL_CREATED = 'ed400003-0000-4000-8000-000000000001'`), fired from `ReferralService::create()` via `notifyEvent`; recipients `userIdsWithRole('administrator')`; new `NotificationCategory::FOLLOW_UP = 'follow_up'`; `audience = 'board'` (staff-side per the engine's legacy vocabulary); `default_channels = ['log','firebase_push']`, `supports_email = false`, `supports_push = true`, `supports_whatsapp = false` | Literal mandate ("when a referral to a specialist is created, notify") through the existing engine — no new channel. Registering the catalog row is sufficient: `NotificationSettingService::resolve()` verified to fall back to `default_channels` with no per-tenant row, and to suppress unknown keys. Email would drag the React Email → `copy-emails` → Mailable pipeline into this change for marginal v1 value (open Q4). Frontend reads no `category` (verified), so the new constant is backend-only metadata |
| D9 | Screening invalidation does NOT cascade to follow-ups in v1 | Invalidate is rare and auditable; the admin cancels the orphaned follow-up manually (mandatory reason). A cascade would need a second event + closure semantics for already-referred children — deferred until field reality demands it (open Q6) |
| D10 | API: Orion resources `only(['index','show','store','update'])` for both entities (no `destroy` — retirement is `cancel`); transitions are explicit endpoints (`start`/`complete`/`cancel`) under `update.follow_up`; `$fillable` excludes `follow_up_status_id`, `closed_by_screening_id`, `completed_at`; transitions `lockForUpdate` + re-check status | Same guarded-writes stack that made screening results unforgeable (three layers: fillable, FormRequest whitelist, service `forceFill`). Explicit transition endpoints keep the FSM out of `update` payloads and give the UI unambiguous actions |
| D11 | `referral` is N-per-follow-up, gated to type `specialist_referral` (422 `FOLLOW_UP_TYPE_MISMATCH`); creating one on `pending`/`overdue` moves the follow-up to `in_progress`; completion stays manual | One red result can legitimately fan out to several specialties (neurology + audiology). The referral act proves work started — but only a human confirms the outcome, so auto-complete would lie |
| D12 | Frontend is pure shared scaffolding: `FollowUpListPage` (`useBaseList`; status filter via `useLazyCatalogs` + `MultiSelectFilter onFirstOpen`; due-window quick filter mapping to server filters; default sort `due_date` asc), `FollowUpDetailPage` (transitions + referrals; modals load catalogs on open), `FollowUpFormModal` (manual create), `ReferralSpecialtyPage` via `createSimpleCatalogPage` | Mandate: no custom-logic screen. Lazy catalog loading per the eager-load anti-pattern rule; `sileo` toasts; `displayFormatDate`; `boneyard-js` skeletons; zero `any` |
| D13 | Registration: 3 module rows `ed000004-…0001/0002/0003` (Seguimientos, Referencias, Especialidades de Referencia) in group Evaluación; `Referencias` HIDDEN (referrals live inside the detail page — a menu row would 404); 18 permission rows; `follow_up_type` + `follow_up_status` appended to `$centralCatalogs`; selects pairs SHIPPED here (unlike screening D21) because the frontend ships in this same change | Follows the ChildRecord catalog-page precedent exactly (`'update:referral_specialty'` entity prefix, `/referral-specialty` route). The `$mapperSelects`/`CatalogType` string-coupled pair travels atomically with the UI that consumes it |
| D14 | `FollowUp` module skeleton is new (nwidart): added to `modules_statuses.json`; provider registers the listener binding and the artisan command | `.project-context/modules.md` already reserves `FollowUp` as its own module ("FSM de seguimiento disparada por evento desde Screening") — high-churn workflow tables stay out of Screening's append-only clinical record |

## 3. Data model (definitive)

Tenant tables: `$table->comment('...')` first line inside the closure, then `BaseModel::columns($table, true)`; every own column `->comment()`; FK-style columns `uuid(...)->index()`, no DB FKs; partial uniques via `DB::statement(... WHERE deleted_at IS NULL)`.

**`follow_up`** — 'Conduct derived from a screening result: reassessment, referral, or confirmatory-test tracking'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `child_id` | uuid, index | NOT NULL | FK to child (denormalized for the inbox and child history) |
| `screening_id` | uuid, index | NOT NULL | FK to the screening whose result mandated this conduct |
| `follow_up_type_id` | uuid, index | NOT NULL | FK to central follow_up_type |
| `follow_up_status_id` | uuid, index | NOT NULL | FK to central follow_up_status; service-derived, never client-writable |
| `due_date` | date | NOT NULL | Date the conduct must happen by; drives the derived overdue state |
| `referral_specialty_id` | uuid | nullable | FK to tenant referral_specialty; suggested specialty for referral follow-ups |
| `closed_by_screening_id` | uuid, index | nullable | FK to the later screening that auto-closed this reassessment; NULL = manual close |
| `completed_at` | timestamp | nullable | Moment the follow-up reached completed (auto or manual) |
| `cancellation_reason` | text | nullable | Mandatory reason captured on cancel |
| `notes` | text | nullable | Clinical/administrative notes |

Indexes: `$table->index(['tenant_id', 'follow_up_status_id', 'due_date'])` (the work inbox); `$table->index(['tenant_id', 'child_id'])`; `UNIQUE (screening_id, follow_up_type_id) WHERE deleted_at IS NULL`.

**`referral`** — 'Concrete referral act of a specialist_referral follow-up'

`follow_up_id` uuid idx NOT NULL — FK to follow_up (type specialist_referral only) · `referral_specialty_id` uuid idx NOT NULL — FK to tenant referral_specialty · `referred_at` date NOT NULL — date the child was referred · `institution` string nullable — receiving institution/unit · `outcome_notes` text nullable — outcome/feedback from the specialist.

### Central catalogs (fixed UUIDs, `CatalogSeeder::upsertCatalog`)

| Table | Rows | Columns beyond base | Constants |
|---|---|---|---|
| `follow_up_type` | 3 | `key` unique, `name` (Reaplicación a 3 meses / Referencia a especialista / Prueba confirmatoria), `sort_order` | `FollowUpType::REASSESS_3_MONTHS = ed400001-0000-4000-8000-000000000001`, `SPECIALIST_REFERRAL = …0002`, `CONFIRMATORY_TEST = …0003` |
| `follow_up_status` | 5 | `key` unique, `name` (Pendiente / En proceso / Completado / Vencido / Cancelado), `sort_order` | `FollowUpStatus::PENDING = ed400002-…0001`, `IN_PROGRESS = …0002`, `COMPLETED = …0003`, `OVERDUE = …0004`, `CANCELLED = …0005` |

### Tenant catalog (`TenantCatalogSeeder::CATALOGS`)

`ReferralSpecialty::class` → Neurología pediátrica, Audiología, Oftalmología, Terapia de lenguaje, Terapia física, Psicología (name-only rows, `firstOrCreate` — never overwrites tenant edits; existing tenants get them via `tenants:seed`).

### Notification catalog

`NotificationEvent::REFERRAL_CREATED = 'ed400003-0000-4000-8000-000000000001'`, `key = 'follow_up.referral_created'`, `name = 'Referencia a especialista creada'`, `category = NotificationCategory::FOLLOW_UP` (new constant `'follow_up'`), `audience = 'board'`, `supports_email = false`, `supports_push = true`, `supports_whatsapp = false`, `default_channels = ['log','firebase_push']`, `is_active = true`, `is_mandatory = false`, `is_hidden = false`.

## 4. Backend surface

```
Modules/Screening/app/Events/ScreeningCompleted.php   # NEW — readonly screening payload
Modules/Screening/app/Services/ScreeningLifecycleService.php  # +1 dispatch line in complete()

Modules/FollowUp/app/
├── Models/            FollowUp, Referral, FollowUpType, FollowUpStatus, ReferralSpecialty
├── Listeners/         CreateFollowUpsFromScreening   # close-then-create, idempotent (D1/D2/D5)
├── Console/           RefreshOverdueFollowUpsCommand # follow-up:refresh-overdue (D7)
├── Http/Controllers/  FollowUpController, ReferralController
├── Http/Requests/     FollowUpCreateRequest, FollowUpUpdateRequest, FollowUpCancelRequest,
│                      ReferralCreateRequest, ReferralUpdateRequest
├── Services/
│   ├── FollowUpCreationService.php   # color matrix + due dates + status derivation (D3/D4)
│   ├── FollowUpLifecycleService.php  # start/complete/cancel, terminal freeze (D10)
│   └── ReferralService.php           # type gate, in_progress side effect, notifyEvent (D8/D11)
└── Providers/         FollowUpServiceProvider (listener + command registration)
```

Routes (`Modules/FollowUp/routes/api.php`, under `['auth:sanctum','initializeTenancy']` + `throttle:api`):

| Route | Action | Guard |
|---|---|---|
| `Orion::resource('follow-up', ...)->only(['index','show','store','update'])` | inbox, detail, manual create, edit open fields | `$keyForPermission = 'follow_up'` |
| `POST follow-up/{id}/start` | pending\|overdue → in_progress | `update.follow_up` |
| `POST follow-up/{id}/complete` | open → completed (manual) | `update.follow_up` |
| `POST follow-up/{id}/cancel` | open → cancelled + reason | `update.follow_up` |
| `Orion::resource('referral', ...)->only(['index','show','store','update'])` | referral capture | `$keyForPermission = 'referral'` |
| `Orion::resource('referral-specialty', ...)` | tenant catalog CRUD | `$keyForPermission = 'referral_specialty'` |

Domain error codes: `FOLLOW_UP_DUPLICATE`, `FOLLOW_UP_FROZEN` (409), `INVALID_STATUS_TRANSITION` (409), `FOLLOW_UP_TYPE_MISMATCH`, `SCREENING_NOT_ELIGIBLE`. Messages in Spanish, codes stable for the UI.

## 5. Listener pipeline (completion transaction)

```
ScreeningLifecycleService::complete()            [existing lock + guards]
  status → completed, completed_at = now()
  event(new ScreeningCompleted($screening))      [REQ-SCR-016, same transaction]
    CreateFollowUpsFromScreening:
      1. auto-close: open reassess_3_months of child, origin visit_date <= this
         visit_date, screening_id != this → completed + closed_by + completed_at  (D5)
      2. create per color matrix (firstOrCreate on (screening_id, type))          (D2/D3)
         due dates from visit_date (D4); status derived from due_date (D7)
commit  → afterCommit notifications (none here; referral notification fires later)
```

## 6. Module registration (exact wiring)

- `modules.csv`: `ed000004-0000-4000-8000-000000000001,Seguimientos,Evaluación` · `…0002,Referencias,Evaluación` · `…0003,Especialidades de Referencia,Evaluación`.
- `permissions.csv`: 6 rows per entity (`follow_up`, `referral`, `referral_specialty`) = 18 rows.
- `module_system.csv`: 3 rows → same Evaluación system as the Tamizajes rows, next free sorts, same group.
- `SystemTableSeeder`: entities `'Seguimientos' => 'follow_up'`, `'Referencias' => 'referral'`, `'Especialidades de Referencia' => 'update:referral_specialty'` (ChildRecord catalog-page prefix pattern); routes `'Seguimientos' => '/follow-up'`, `'Especialidades de Referencia' => '/referral-specialty'`; icons for all 3 (verify names exist in `iconMap.ts` at apply — e.g. `IconChecklist`, `IconCategory`); visibility: `Referencias` in `$hidden`.
- `RolePermissionTableSeeder`: 18 permission names appended; `follow_up_type` + `follow_up_status` appended to `$centralCatalogs`. Run `ResetPermissionTableSeeder`.
- `CatalogSeeder`: `seedFollowUpTypes()`, `seedFollowUpStatuses()`; add the `REFERRAL_CREATED` row to `seedNotificationEvents()`.
- `TenantCatalogSeeder::CATALOGS`: `ReferralSpecialty::class` block.
- `AngularController::$mapperSelects`: `'FollowUpType'`, `'FollowUpStatus'`, `'ReferralSpecialty'`; `useCatalogs.ts::CatalogType`: `FOLLOW_UP_TYPE`, `FOLLOW_UP_STATUS`, `REFERRAL_SPECIALTY`.
- `modules_statuses.json`: add `"FollowUp": true`.
- `routes/console.php`: `Schedule::command('follow-up:refresh-overdue')->dailyAt('00:15')`.

## 7. Testing plan

| Layer | Coverage |
|---|---|
| Feature (`Modules/FollowUp/tests/Feature/`) | `FollowUpCatalogSeedTest` (central UUIDs/keys, tenant specialties per tenant), `FollowUpCreationFromScreeningTest` (full color matrix incl. recurrence and green-none; due dates; atomicity — a forced listener failure leaves the screening `scored`), `FollowUpIdempotencyTest` (double dispatch no-op; manual duplicate 422), `FollowUpAutoCloseTest` (posterior closes with `closed_by` + `completed_at`; backfilled older visit does not; other types/children untouched; close-then-create on second yellow), `FollowUpOverdueCommandTest` (both directions; second run zero changes; manual states untouched; per-tenant isolation), `FollowUpLifecycleTest` (transition matrix; forged status/closed_by/completed_at ignored; terminal 409; update whitelist), `ReferralTest` (type gate; in_progress side effect; N referrals; frozen 409), `ReferralNotificationTest` (dispatched with defaults to administrators; tenant opt-out suppresses; catalog row aligned with the constant), `FollowUpTenantIsolationTest`, `FollowUpMenuRegistrationTest` (menu rows, Referencias hidden, 403 matrix) |
| Feature (`Modules/Screening/tests/Feature/`) | `ScreeningCompletedEventTest` — dispatched once on complete, absent on score/rescore/invalidate (REQ-SCR-016) |
| Unit | Due-date/status derivation, overdue predicate |
| Frontend (Vitest) | `followUp` schema parse + form validation; list/detail render smoke with mocked API |

Backend baseline 737 must stay green; frontend baseline 221 must stay green.

## 8. Open decisions

| Q | Question | Default encoded in this design |
|---|---|---|
| Q1 | Due-date offsets: 3 months (reassess) is clinical mandate; are 30 days right for referral/confirmatory? | 30 days both; adjust with clinical guidance before apply |
| Q2 | Recurrence definition — consecutive yellow only, or "any second yellow ever"? | Consecutive: current yellow AND frozen `previous_result_color_id` yellow (deterministic, matches "si reincide" reading) |
| Q3 | Notification recipients — only `administrator`, or also `account.owner`/`company.admin`? | `administrator` only (operational role); broaden in a permissions pass |
| Q4 | Email channel for `follow_up.referral_created` | Deferred (supports_email=false); needs the React Email template pipeline |
| Q5 | "Today" for overdue — app timezone vs per-tenant timezone | App timezone (single-market Mexico); revisit if tenants gain a timezone attribute |
| Q6 | Should invalidating a screening cancel its open auto-created follow-ups? | No cascade in v1 (D9); manual cancel with reason |

## 9. Explicitly unchanged

`Modules/Screening/app/Services/Scoring/**` and `RuleSetLoading/**` (frozen engine + oracle gate), every existing Screening table/endpoint/catalog (only the event dispatch line and the new event class are added), Instrument content, Clinic/ChildRecord, the notification engine internals (`DispatchesNotifications`, `NotificationDispatcher`, `TemplateRenderer`, `NotificationSettingService` — consumed, not modified), existing `NotificationEvent` rows, quota binding, `REPORT_MODEL_MAP`. No `alter` migration touches an existing table.
