# SDD Design — Delinquency-Based Feature Restrictions

- **Change:** `delinquency-restrictions`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** design (the HOW at architectural level)
- **Artifact store:** hybrid
- **Date:** 2026-07-14
- **Reads:** proposal.md (this change), spec (Engram `sdd/delinquency-restrictions/spec`, R-DELQ-1..11), the approved session plan, `ChargeStatusService`, `ChargePolicyService`, `ConfigurationController` (package-settings pattern), `VisitorController`, `PackageService`, `usePackageCatalogOptions.ts`, `configSections.tsx`, `shared/utils/money.ts`.
- **Authority order on conflict:** laravel-backend-architecture / stancl-tenancy / react-frontend-architecture skills > `CLAUDE.md` > this design. Plan "Decisiones tomadas" are settled and not reopened here.

---

## 0. Executive summary

One new service in Collections (`DelinquencyRestrictionService`) is the single source of truth for the blocking rule; five service-layer asserts call it; one exception class (`DelinquencyRestrictedException`) owns the audience-split 403 payload so the guard-facing register can never leak debt data by construction. Settings ride the existing generic `setting` table (six `delinquency.*` slugs, no migrations). Frontend adds one self-contained config section, one resident hook, notices inside the existing wizard/modal, and a lazy restricted-units check in the guard intake forms.

## 1. Architecture approach

No new patterns — four existing seams, reused:

| Concern | Existing seam reused |
|---|---|
| Settings storage + config endpoints | `package.*` slugs + `getPackageSettings`/`updatePackageSettings` (flat wire keys, `$slugMap`, one `whereIn` read) — **NOT** the dotted-key `getCollectionSettings` (documented no-op bug with Laravel dot-as-nested traversal) |
| "What is owed" | `ChargeStatusService::effectiveExpected(Charge)` — public, canonical; the outstanding formula is already duplicated 4×, this design forbids a 5th copy |
| user → active units | `ScopesToResidentUnits::ownUnitIds()` (`Modules/Collections/.../Concerns/ScopesToResidentUnits.php:35`) on the new controller |
| 403 with machine code | `EnsureAmenityReservationsEnabled`'s `{ message, code }` shape, code `DELINQUENT_RESTRICTED` |

**Module ownership:** the service, settings class, exception, controller, seeder rows, and backfill command all live in **Modules/Collections** (debt is Collections' domain). AmenityReservations, Access, and Package gain a call-site dependency on `Modules\Collections\Services\DelinquencyRestrictionService` — acceptable: those modules already depend on Collections-owned facts (charge status), and centralizing beats three drifting copies of the rule.

**Guard placement:** service layer. Verified: `FormRequest::authorize()` is dead code on Orion routes (`DelegatingModuleRequest` delegates only rules/messages/attributes/withValidator) and there are no Policies (`TraitController::authorize()` checks only Sanctum token abilities). Middleware was rejected: the unit id lives in the request body / loaded entity, which differs per action — only the service has the unit in hand.

### Data flow (blocking path)

```
POST /reservation | /visitor | visitor approve/reject | package register/batch
        │ (existing auth:sanctum + initializeTenancy + ability check)
        ▼
Domain service (ReservationService / AccessService / PackageService)
        │ assertNotRestricted(unitId, feature)
        ▼
DelinquencyRestrictionService
   ├─ settings(): ONE whereIn query over the six delinquency.* slugs (hoisted, memoized per instance)
   ├─ unit.charge_status_id === ChargeStatus::OVERDUE?  (direct query — column is not fillable, has no relation)
   ├─ overdueBalanceFor(unitId) >= min_overdue_amount?  (skipped when min = 0)
   └─ blocked → throw DelinquencyRestrictedException::forResident(...) | ::forGuard()
                          │ (exception renders itself → 403 JSON)
                          ▼
              resident register: { message, code, overdue_balance: TenantMoney, notice_text }
              guard register:    { message: 'Esta unidad no puede recibir paquetes.', code }
```

Immediate unblock (R-DELQ-9) needs zero new code: payment application already recomputes `unit.charge_status_id` in the same transaction; the next check simply reads the fresh aggregate.

## 2. Settings — `DelinquencySettings` + six slugs

New `backend/Modules/Collections/app/Support/DelinquencySettings.php`, mirroring `CollectionSettings` (final class, slug constants + defaults + `all(): array` slug list for batch reads/seeding):

| Constant | Slug | Type | Default | Meaning |
|---|---|---|---|---|
| `RESTRICTIONS_ENABLED` | `delinquency.restrictions_enabled` | BOOLEAN | `false` | Master switch |
| `MIN_OVERDUE_AMOUNT` | `delinquency.min_overdue_amount` | INTEGER | `0` | Minimum overdue balance, **minor units**; `0` = any OVERDUE unit |
| `BLOCK_PACKAGE_INTAKE` | `delinquency.block_package_intake` | BOOLEAN | `false` | Unit cannot receive packages |
| `BLOCK_AMENITIES` | `delinquency.block_amenities` | BOOLEAN | `false` | Unit cannot create amenity reservations |
| `BLOCK_VISITOR_ACCESS` | `delinquency.block_visitor_access` | BOOLEAN | `false` | Unit cannot generate/authorize visitor access |
| `NOTICE_TEXT` | `delinquency.notice_text` | STRING | default text below | Resident-facing notice (guard message is fixed, NOT a setting) |

Default `notice_text` (Mexican Spanish): `"Tu unidad tiene adeudos vencidos, por lo que esta función está restringida. Ponte al corriente con tus pagos o acude a la administración para regularizar tu situación."`

This is the project's **first money-valued setting**: `Setting` casts only BOOLEAN|STRING|INTEGER|JSON, so it is stored as INTEGER minor units (`5000` pesos → `500000`).

## 3. Service — `DelinquencyRestrictionService`

New `backend/Modules/Collections/app/Services/DelinquencyRestrictionService.php`:

```php
final class DelinquencyRestrictionService
{
    public const FEATURE_PACKAGE_INTAKE = 'package_intake';
    public const FEATURE_AMENITIES = 'amenities';
    public const FEATURE_VISITOR_ACCESS = 'visitor_access';

    public function __construct(private readonly ChargeStatusService $chargeStatusService) {}

    /** Overdue balance for one unit, minor units. */
    public function overdueBalanceFor(string $unitId): int;

    /** The single blocking rule. $feature = one of the FEATURE_* constants. */
    public function isRestricted(string $unitId, string $feature): bool;

    /** @throws DelinquencyRestrictedException (403) when restricted. */
    public function assertNotRestricted(string $unitId, string $feature): void;

    /** PER-UNIT payload for GET /my-restrictions, computed over the caller's active unit ids. */
    public function stateForUnits(array $unitIds): array;

    /** Unit ids currently restricted for $feature — batch, no per-unit queries. */
    public function restrictedUnitIds(string $feature): array;

    /** Memoized per instance: ONE Setting::whereIn over the six slugs. */
    private function settings(): array;
}
```

- **Blocking rule (one place, never re-derived at call sites):** `restrictions_enabled && block_{feature} && unit.charge_status_id === ChargeStatus::OVERDUE && overdueBalanceFor(unit) >= min_overdue_amount`. `ChargeStatus::OVERDUE` is a fixed central-catalog UUID constant — compared directly, no lookup. **OVERDUE only, never PARTIAL** (PARTIAL = paid something but still within term; do NOT copy the `[OVERDUE, PARTIAL]` filter from `PaymentController::delinquency()` / `computeAging()`).
- **`overdueBalanceFor()`** reuses `effectiveExpected()`:
  ```php
  Charge::query()
      ->where('unit_id', $unitId)
      ->where('charge_status_id', ChargeStatus::OVERDUE)
      ->where('is_cancelled', false)
      ->get(['id', 'expected_amount', 'discount_amount', 'surcharge_amount', 'paid_amount'])
      ->sum(fn (Charge $c) => max(0, $this->chargeStatusService->effectiveExpected($c)
          - (int) $c->getAttributes()['paid_amount']));
  ```
  Hits the existing `charge` indexes (`unit_id`, `charge_status_id`, `(unit_id, is_cancelled, due_date)`). No caching in v1 (settled out of scope; point actions, measure first).
- **Settings hoisting:** `Setting::getValue()` is uncached (one SELECT per call) — the service reads all six slugs in ONE `whereIn` (same shape as `getPackageSettings`) and memoizes on the instance. For the memoization to span the request, the service is registered with **`$this->app->scoped(DelinquencyRestrictionService::class)`** in `CollectionsServiceProvider` — one instance per request, flushed between Octane requests. **Never `singleton()`** (Octane would pin one tenant's settings across requests), and not the plain default binding either (that creates a new instance per *resolution*, so each hook point would re-read the settings).
- **Per-check cost:** master off → 1 query; enabled + not OVERDUE → 2; OVERDUE + threshold > 0 → 3 small indexed queries. `min_overdue_amount = 0` skips the balance query entirely.
- **`restrictedUnitIds()` — batch, N+1-proof (R-DELQ-7 backing):**
  1. `settings()` — 1 query; short-circuit to `[]` when master or `block_{feature}` is off.
  2. `Unit::query()->where('charge_status_id', ChargeStatus::OVERDUE)->pluck('id')` — 1 query, tenant-scoped by `BelongsToTenant` (a `where()` on a non-fillable column is fine; fillable only guards mass assignment).
  3. `min == 0` → return those ids (no balance work at all).
  4. Else ONE query loading all OVERDUE non-cancelled charges `whereIn('unit_id', $ids)` selecting `['unit_id', 'id', 'expected_amount', 'discount_amount', 'surcharge_amount', 'paid_amount']` (**`unit_id` included — it is the grouping key**) → `groupBy('unit_id')` in PHP → sum via `effectiveExpected` → filter `>= min`. **Never `Setting::getValue` or a per-unit query in a loop.** Worst case = 3 queries total, bounded by the tenant's OVERDUE charge count (indexed on `charge_status_id`).
- **`stateForUnits()` is PER UNIT** — never an ANY-aggregate. A resident can hold unit A (current) and unit B (OVERDUE); an aggregate would make the client restrict non-delinquent unit A, which the backend would allow — violating R-DELQ-1 ("a unit is delinquent only when *its* status is OVERDUE"). The method returns one entry per active unit: `{ unit_id, restricted, features: { amenities, visitor_access }, overdue_balance? }`, with `overdue_balance` present only on restricted units. Computation reuses the exact batch shape of `restrictedUnitIds()` scoped to `$unitIds` (settings `whereIn` → OVERDUE pluck among `$unitIds` → one grouped charge load) — still 3 queries max, no per-unit loop.

## 4. The audience-split 403 — `DelinquencyRestrictedException`

New `backend/Modules/Collections/app/Exceptions/DelinquencyRestrictedException.php`. **The split lives in the constructors, not at call sites** — the guard path physically cannot carry an amount because `forGuard()` takes no money argument:

```php
final class DelinquencyRestrictedException extends Exception
{
    public static function forResident(int $overdueBalanceMinor, string $noticeText): self;
    public static function forGuard(): self;

    public function render(): JsonResponse; // Laravel renders the exception itself → 403
}
```

- **Resident register** (`amenities`, `visitor_access`): `{ "message": "Función restringida por adeudos vencidos.", "code": "DELINQUENT_RESTRICTED", "overdue_balance": TenantMoney::make($minor), "notice_text": <delinquency.notice_text> }`. Money crosses the wire as the serialized Money object (project HARD rule), never a raw int.
- **Guard register** (`package_intake`): `{ "message": "Esta unidad no puede recibir paquetes.", "code": "DELINQUENT_RESTRICTED" }`. The string is a class constant, deliberately **NOT configurable** (nobody can write "Unidad morosa" into it), and contains no amount, no `notice_text`, no debt wording.
- `assertNotRestricted()` selects the factory by feature key (`package_intake` → `forGuard()`, else `forResident(...)`). Privacy tests assert the guard payload contains neither an amount key nor the substrings "adeudo"/"moroso"/"deuda" (R-DELQ-6).

## 5. Hook points (five, exact insertion)

| # | Feature key | File · method | Insertion |
|---|---|---|---|
| 1 | `amenities` | `Modules/AmenityReservations/app/Services/ReservationService.php` · `create()` | Immediately after `authorizeUnitMembership()` (~L992) — the docblock says this service "owns every business rule". `assertNotRestricted($data['unit_id'], FEATURE_AMENITIES)`. Applies to everyone incl. admins/board (settled: no resident-level exceptions). |
| 2 | `visitor_access` | `Modules/Access/app/Services/AccessService.php` · `generateQr()` | First business rule in the method, on the incoming `unit_id` (path: `POST /visitor` → `VisitorController::performStore` → `generateQr`). |
| 3 | `visitor_access` | `Modules/Access/app/Http/Controllers/VisitorController.php` · `approve()` / `reject()` | After the existing `authorizeUnitMembership($request, $visitor)` (L216/L233), on `$visitor->unit_id`. Membership first, restriction second — a non-member must keep getting the plain `AuthorizationException`, not a debt-shaped 403. |
| 4 | `package_intake` | `Modules/Package/app/Services/PackageService.php` · `register()` | First statement inside the `DB::transaction` closure, on `$data['unit_id']`, before `persistWithUniquePickupCode()`. Covers the Orion single-intake path AND every future caller. |
| 5 | `package_intake` | `Modules/Package/app/Services/PackageService.php` · `batchRegister()` | Pre-loop guard BEFORE opening the outer transaction: intersect `array_unique(array_column($rows, 'unit_id'))` with `restrictedUnitIds(FEATURE_PACKAGE_INTAKE)`; any hit → `forGuard()` immediately. Fails fast (no resident pre-resolution, no savepoints burned); hook 4 stays as same-transaction defense in depth. |

Placement note vs the plan: the plan's table listed the batch hook at `PackageController::batchRegister()`; the controller (L137) only delegates to `PackageService::batchRegister()` (L182), so the guard goes in the service — same lock, one layer down, consistent with the "guards live in the service layer" principle. Controller and `POST /package/intake-batch` route are untouched.

**Deliberately NOT touched** (R-DELQ-5): `AccessLogController::myQr()/myLog()`, `PackageController::verifyCode()`, `PackageService::handover()`, `AmenityController::index/show/availability`, `VisitorController::cancelQr()` (cancelling reduces access; blocking it would trap a QR).

## 6. Endpoints

### 6.1 `GET/PUT /config/delinquency-settings` — Configuration module

`ConfigurationController::getDelinquencySettings()` / `updateDelinquencySettings()`, cloning the **package-settings pattern**: flat wire keys ↔ `delinquency.*` slugs via a `$slugMap`, one `Setting::whereIn` read, `$request->only()` on write (only sent keys are updated → the frontend can send just the diff). Gates: `requireAbility('read.configuration')` / `('update.configuration')` — no new permission.

- **GET** returns flat keys; `min_overdue_amount` is serialized as `TenantMoney::make((int) $value)` (`{amount, currency, formatted}`) — money HARD rule; every other key returns `resolved_value` raw.
- **PUT** validation (flat keys — dotted keys break Laravel validation, the documented `collection-settings` bug): `restrictions_enabled` boolean · `min_overdue_amount` integer, min:0 (client sends minor units) · `block_package_intake`/`block_amenities`/`block_visitor_access` boolean · `notice_text` string, max:500. All `sometimes` (diff-only saves).
- Routes in `Modules/Configuration/routes/api.php` next to the package-settings pair.

### 6.2 `GET /my-restrictions` — Collections module

New `Modules/Collections/app/Http/Controllers/DelinquencyRestrictionController.php` using the existing `ScopesToResidentUnits` concern (`ownUnitIds()`, `whereNull('end_date')` — reuse, do not rewrite). Route in `Modules/Collections/routes/api.php` with `['auth:sanctum', 'initializeTenancy']`; **no ability gate** — any authenticated tenant user may read their OWN state (the payload only ever describes the caller's units; nothing about anyone else). Registered before any colliding Orion resource per the literal-routes gotcha (no `Orion::resource('my-restrictions')` exists, so placement is safe by construction — still keep it in the literal-routes block).

The payload is **per unit** (see §3 — an aggregate would restrict a multi-unit resident's good-standing units in violation of R-DELQ-1). Top-level `restricted` is a convenience OR across units; the client must gate on the entry matching the unit the user selects:

```json
{
  "restricted": true,
  "notice": "Tu unidad tiene adeudos vencidos…",
  "units": [
    { "unit_id": "uuid-A", "restricted": false,
      "features": { "amenities": false, "visitor_access": false } },
    { "unit_id": "uuid-B", "restricted": true,
      "features": { "amenities": true, "visitor_access": true },
      "overdue_balance": { "amount": "500000", "currency": "MXN", "formatted": "$5,000.00" } }
  ]
}
```

`overdue_balance` appears only on restricted units (TenantMoney). `package_intake` is deliberately absent — the resident is never the actor of intake, and exposing it adds nothing.

### 6.3 `GET /package/restricted-units` — Package module

`PackageController::restrictedUnits()`: `$this->authorize('create.package')` then `response()->json(['unit_ids' => $service->restrictedUnitIds(FEATURE_PACKAGE_INTAKE)])`. **Unit ids only** — no amounts, no statuses, no names (R-DELQ-7). Route registered in `Modules/Package/routes/api.php` **before** `Orion::resource('package', …)` (otherwise `restricted-units` matches the resource's `{id}` show route — documented gotcha in all three modules). Residents lack `create.package` → 403. Deliberately NOT exposed via `/selects`, which has no permission gate (`AngularController::selects`) — a resident could otherwise enumerate who owes.

## 7. Seeded defaults + backfill

- Extend `Modules/Collections/database/seeders/CollectionsDatabaseSeeder.php` with the six rows, using that file's **`Setting::withTrashed()` create/restore pattern — NOT `firstOrCreate`** (the `(tenant_id, slug)` unique index is not partial; a soft-deleted row still occupies it and `firstOrCreate` would explode). Runs for new tenants via the existing `TenantCreated` → `tenants:seed` chain.
- New `Modules/Collections/app/Console/Commands/BackfillDelinquencySettingsCommand.php` cloning `BackfillCollectionSettingsCommand` (iterate tenants, `tenancy()->initialize`, seed missing rows, `tenancy()->end()`), run once at deploy for existing tenants.

## 8. Frontend

### 8.1 `DelinquencyConfigSection` (admin)

New `frontend/src/modules/configuration/components/DelinquencyConfigSection.tsx`, self-contained on the `PackageConfigSection` pattern (React Query + `useSectionEdits` + local `apiClient` calls to `/config/delinquency-settings`, PUT sends **only the `edits` diff**). One registry entry in `configSections.tsx`:

```tsx
{ key: 'delinquency', label: 'Morosidad', icon: ShieldBan, title: 'Restricciones por morosidad',
  description: 'Restringe funciones a las unidades con adeudos vencidos: define desde qué monto aplica y qué funciones se bloquean (reservas de amenidades, accesos de visita, recepción de paquetes).',
  render: ({ canUpdate }) => <DelinquencyConfigSection canUpdate={canUpdate} /> }
```

Money field (first money setting): no shared `MoneyInput` exists — compose `<Input type="number" step="0.01" min="0">` like `ExpenseFormModal`/`ProjectFormModal`. Edits in **pesos**; load via `moneyToMinorUnits(...)` + `minorUnitsToPesos(...)`, save via `pesosToMinorUnits(...)` (`shared/utils/money.ts`). Rounding test: `5000` → `500000`. UI copy in Mexican Spanish (tú).

### 8.2 Resident — `useMyRestrictions` + notices

New `frontend/src/modules/collections/hooks/useMyRestrictions.ts`: `useQuery({ queryKey: ['my-restrictions'], queryFn: GET /my-restrictions, staleTime: 60_000 })`, returning the per-unit payload of §6.2 plus a derived lookup (`unitState(unitId)` → the matching `units[]` entry, or `undefined`). **Called only inside components that mount when the action starts** (the wizard/modal bodies), so it fires on action initiation, not per page/list; the shared queryKey deduplicates across both consumers. No polling.

**Gating is on the SELECTED unit, never an aggregate** — both flows are multi-unit (`ReserveAmenityWizard.tsx:52-77` radio group over the resident's units, step "Elige tu unidad"; `VisitorFormModal.tsx:140-146` `UnitCombobox` "Unidad que visita"). A resident with unit A current and unit B OVERDUE must still be able to act for unit A; only choosing B triggers the notice.

- `frontend/src/modules/reservation/components/ReserveAmenityWizard.tsx`: the "Reservar" button (`AmenityBrowseDetailPage`, ~L134) stays untouched — visible, enabled, opens normally, and the wizard **always reaches its unit picker** (R-DELQ-11). When the chosen unit's `features.amenities` is true, the wizard renders the notice panel for THAT unit — `displayMoney(units[i].overdue_balance)` + `notice` + how to settle ("Realiza tu pago para reactivar las reservas.") — and does not advance past the picker while that unit is selected; picking a good-standing unit proceeds normally. Single-unit residents (auto-selected unit) see the notice immediately, which degrades to the original behavior. The backend 403 remains the authority if the client state is stale.
- `frontend/src/modules/access/components/VisitorFormModal.tsx`: same treatment on the `UnitCombobox` selection for `features.visitor_access` — "Registrar visita" (`VisitorListPage`, ~L464) opens normally; the notice renders under the unit field when the selected unit is restricted, and submit is stopped only for that selection.

This keeps R-DELQ-11 intact — the notice still renders only when the action is initiated, inside the wizard/modal — while adding per-unit precision to WHERE inside the flow it appears (after unit choice).

### 8.3 Guard — subtle note in intake forms

Extend `usePackageCatalogOptions.ts`: add a `restrictedUnitIds: Set<string>` fed by a `useQuery({ queryKey: ['package-restricted-units'], queryFn: GET /package/restricted-units, enabled: unitsEnabled })` where `unitsEnabled` is a local state flipped inside the existing `enableUnits()` — i.e. **the restricted-units fetch rides the exact lazy trigger the unit dropdown already has** (`onOpenChange` in `PackageForm.tsx` / `BulkIntakeForm.tsx`). Zero fetch on list mount (eager-catalog anti-pattern; `@infra-cost-reviewer` target).

In `PackageForm.tsx` and `components/BulkIntakeForm.tsx`: when a selected unit is in the set → subtle inline note under the unit field, exactly **"Esta unidad no puede recibir paquetes."** — no amount, no reason — and the submit is blocked for that row (batch: per-row note; submit blocked while any restricted row remains). The unit stays selectable (nothing hidden/disabled in the dropdown); only submission is stopped, mirroring the server behavior.

## 9. File changes

| File | Action |
|---|---|
| `backend/Modules/Collections/app/Support/DelinquencySettings.php` | Create |
| `backend/Modules/Collections/app/Services/DelinquencyRestrictionService.php` | Create |
| `backend/Modules/Collections/app/Exceptions/DelinquencyRestrictedException.php` | Create |
| `backend/Modules/Collections/app/Http/Controllers/DelinquencyRestrictionController.php` | Create |
| `backend/Modules/Collections/app/Console/Commands/BackfillDelinquencySettingsCommand.php` | Create |
| `backend/Modules/Collections/database/seeders/CollectionsDatabaseSeeder.php` | Modify (+6 rows, withTrashed pattern) |
| `backend/Modules/Collections/routes/api.php` | Modify (`GET my-restrictions`) |
| `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` | Modify (+2 methods) |
| `backend/Modules/Configuration/routes/api.php` | Modify (GET/PUT delinquency-settings) |
| `backend/Modules/AmenityReservations/app/Services/ReservationService.php` | Modify (hook 1) |
| `backend/Modules/Access/app/Services/AccessService.php` | Modify (hook 2) |
| `backend/Modules/Access/app/Http/Controllers/VisitorController.php` | Modify (hook 3) |
| `backend/Modules/Package/app/Services/PackageService.php` | Modify (hooks 4+5) |
| `backend/Modules/Package/app/Http/Controllers/PackageController.php` | Modify (`restrictedUnits()`) |
| `backend/Modules/Package/routes/api.php` | Modify (route BEFORE Orion resource) |
| `frontend/src/modules/configuration/components/DelinquencyConfigSection.tsx` | Create |
| `frontend/src/modules/configuration/configSections.tsx` | Modify (+1 entry) |
| `frontend/src/modules/collections/hooks/useMyRestrictions.ts` | Create |
| `frontend/src/modules/reservation/components/ReserveAmenityWizard.tsx` | Modify (notice) |
| `frontend/src/modules/access/components/VisitorFormModal.tsx` | Modify (notice) |
| `frontend/src/modules/package/hooks/usePackageCatalogOptions.ts` | Modify (restricted set, lazy) |
| `frontend/src/modules/package/PackageForm.tsx`, `components/BulkIntakeForm.tsx` | Modify (note + submit block) |

**No migrations. No new tables. No new permissions.**

## 10. Testing strategy

| Layer | What | How |
|---|---|---|
| Unit/Feature (host `php artisan test`, single process) | Rule matrix: off→never blocks; OVERDUE below threshold→no; at/above→yes; `min=0`→any OVERDUE; **PARTIAL within term→NOT blocked** (the trap this design avoids) | `DelinquencyRestrictionServiceTest` seeding charges per state via `ChargeStatusService` |
| Feature | One 403 per hook (reservation create, `POST /visitor`, approve/reject, register, batch) + never-blocked 200s (`my-qr`, `my-log`, `handover`, amenity browse) | HTTP tests per module |
| Feature — privacy | `package_intake` 403 + `restricted-units` payload contain no amount and none of "adeudo/moroso/deuda"; resident → `restricted-units` 403 | String/shape assertions |
| Feature — tenancy | Tenant A settings never restrict tenant B | Two-tenant test (R-DELQ-10) |
| Feature — accepted board bypass (pin, do NOT "fix") | `ReservationService::authorizeUnitMembership` (L996) lets board/admin (`tokenCan('update.amenity_reservation')` or `*`) reserve for a unit they don't belong to; a restricted foreign unit then returns `forResident(...)` carrying THAT unit's balance. Accepted: those roles already hold debt-read permissions (`RolePermissionTableSeeder.php:367,420,451,470`; treasurer is finance-wide, president holds `report_collections`) — this is not an escalation. `VisitorController::authorizeUnitMembership` (L248) has no such bypass, so the asymmetry is amenities-only. | Test asserts a board member reserving for a restricted foreign unit gets the resident-register 403 with that unit's balance — pinning the behavior so a future reader doesn't mistake it for a leak |
| Feature — multi-unit precision | Resident with unit A current + unit B OVERDUE: `my-restrictions` marks only B; reservation/visitor actions for A succeed 200, for B → 403 | Per-unit payload + hook tests (R-DELQ-1 client/server consistency) |
| Frontend (Vitest) | Config section sends only diff; `5000` pesos ↔ `500000`; "Reservar" stays **enabled** with restriction active; intake note renders only for restricted unit | Component + util tests |
| Gates | `vendor/bin/pint --dirty`, `pnpm lint`, `pnpm build`, `pnpm test`; then `/review-stack` + `@infra-cost-reviewer` | Post-apply |

## 11. Threat matrix

N/A — no routing (CLI/tool), shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. New HTTP endpoints follow the project's existing gate/middleware conventions (covered by §6 + privacy tests).

## 12. Migration / rollout

No data migration. All defaults ship **disabled**; deploy is inert until an admin enables it. Backfill command seeds existing tenants at deploy. Operational rollback = turn off `delinquency.restrictions_enabled` per tenant (instant). Code rollback = revert commits; leftover `setting` rows are inert.

## 13. ADR-style decision log

### ADR-1: Guard in the service layer (not FormRequest / Policy / middleware)
**Choice:** `assertNotRestricted()` called from domain services (+2 controller custom actions that have no service seam for approve/reject).
**Rejected:** FormRequest `authorize()` — dead code on Orion routes (`DelegatingModuleRequest` never delegates it). Policies — none exist; `TraitController::authorize()` bypasses the Gate. Route middleware — no unit id at route level.
**Rationale:** only the service holds the resolved unit; verified against vendored qch/core.

### ADR-2: Single exception factory owns the audience split
**Choice:** `DelinquencyRestrictedException::forResident(int, string)` / `::forGuard()` with a self-rendering 403; the guard constructor takes no money argument.
**Rejected:** per-call-site payload formatting — five call sites, one forgotten `unset` leaks a resident's debt to the guard.
**Rationale:** privacy by construction; the leak is a type error, not a review catch. Guard string is a non-configurable constant.

### ADR-3: OVERDUE-only criterion
**Choice:** `charge_status_id === ChargeStatus::OVERDUE` (central catalog UUID constant) + balance threshold.
**Rejected:** copying `[OVERDUE, PARTIAL]` from `delinquency()`/`computeAging()` — verified `resolveStatusKey()` orders OVERDUE before PARTIAL, so PARTIAL = paid-something-within-term, not delinquent.
**Rationale:** blocking a unit that is current on its grace period is a false positive with real-world fallout.

### ADR-4: Reuse `effectiveExpected()`, batch reads, no v1 cache
**Choice:** balance = Σ max(0, effectiveExpected − paid) over OVERDUE non-cancelled charges; `restrictedUnitIds()` = 3 queries max (settings whereIn → OVERDUE unit pluck → one grouped charge load, `unit_id` in the select as grouping key); settings memoized on a **`scoped()`** per-request instance registered in `CollectionsServiceProvider` — never `singleton()` (Octane pins tenants), never the default binding (new instance per resolution defeats the memoization).
**Rejected:** 5th copy of the outstanding formula; `Setting::getValue` per slug (6 uncached SELECTs per check); per-unit balance queries in a loop; a cached balance column (settled out of scope — measure first).

### ADR-5: Dedicated gated endpoint for restricted units
**Choice:** `GET /package/restricted-units` gated `create.package`, ids only, registered before the Orion resource.
**Rejected:** riding `/selects` — it has NO permission gate; any resident could enumerate delinquent units. Returning statuses/amounts — the guard needs only "can this unit receive?".

### ADR-6: Batch guard in `PackageService::batchRegister()` (not the controller)
**Choice:** pre-transaction intersect against `restrictedUnitIds()` + keep the per-row assert in `register()` as defense in depth.
**Rejected:** controller-level check (violates the service-guard principle; controller only delegates); relying on the per-row assert alone (burns the transaction and resident pre-resolution before failing).

### ADR-7: `min_overdue_amount` wire shape
**Choice:** stored INTEGER minor units; config GET serializes it via `TenantMoney::make()` (money HARD rule); PUT accepts an integer in minor units produced by `pesosToMinorUnits`; resident-facing `overdue_balance` always TenantMoney.
**Rejected:** raw int on GET (violates rule 17); pesos-as-decimal anywhere (float money).

## 14. Open questions

None blocking. One apply-time note: pick the exact notice-panel composition (shadcn `Alert` vs card) matching each host component's existing tokens.
