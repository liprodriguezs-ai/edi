# Tasks — Delinquency Restrictions

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~2,400–2,700 (additions + deletions; backend production ~450, backend tests ~1,300, frontend production ~450, frontend tests ~400) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 10 work units, one per phase below (PR1 → PR10) |
| Delivery strategy | ask-on-risk |
| Chain strategy | feature-branch-chain |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Foundation: settings + service + exception + `scoped()` binding | PR1 (base: tracker branch) | `php artisan test --filter=DelinquencyRestrictionServiceTest` | `php artisan tinker` — call `assertNotRestricted` on a seeded OVERDUE unit | Delete the 3 new files + provider binding; nothing else depends on them yet |
| 2 | Persistence: seeder rows + backfill command | PR2 (base: PR1) | `php artisan test --filter=BackfillDelinquencySettings` | `php artisan delinquency:backfill-settings --pretend` (or equivalent dry option) | Revert seeder diff; backfilled `setting` rows are inert data, no cleanup needed |
| 3 | Config API: `config/delinquency-settings` GET/PUT | PR3 (base: PR2) | `php artisan test --filter=DelinquencySettingsConfigTest` | `curl -X PUT .../api/config/delinquency-settings` against local Octane | Revert controller methods + routes; settings stay inert (master switch off) |
| 4a | Hooks 1–3: amenities + visitor-access guards | PR4a (base: PR3) | `php artisan test --filter=Delinquency.*Hook` | Reserve/generate-QR against a seeded OVERDUE unit locally | Remove the 3 `assertNotRestricted` call sites; services fall back to pre-change behavior |
| 4b | Hooks 4–5: package guards + privacy/tenancy/never-blocked/board-bypass/immediate-unblock tests | PR4b (base: PR4a) | `php artisan test --filter=Package.*Delinquency` + full `DelinquencyRestriction` suite | Register/batch-register a package against a restricted unit locally | Remove the 2 package call sites; test suite reverts with it |
| 5 | Resident endpoint `GET /my-restrictions` | PR5 (base: PR4b) | `php artisan test --filter=DelinquencyRestrictionControllerTest` | `curl .../api/my-restrictions` as a resident token | Remove controller + route; no other code depends on it |
| 6 | Guard endpoint `GET /package/restricted-units` | PR6 (base: PR5) | `php artisan test --filter=PackageRestrictedUnitsTest` | `curl .../api/package/restricted-units` as guard token | Remove method + route line; independent of §5 |
| 7 | Frontend config section | PR7 (base: PR6) | `pnpm vitest run DelinquencyConfigSection` | `pnpm dev` — open Configuración → Morosidad | Delete component + registry entry; config screen unaffected |
| 8 | Frontend resident notices | PR8 (base: PR7) | `pnpm vitest run useMyRestrictions ReserveAmenityWizard VisitorFormModal` | `pnpm dev` — reserve/register-visit flow as a delinquent resident | Delete hook + revert wizard/modal diffs; flows return to current behavior |
| 9 | Frontend guard notices | PR9 (base: PR8) | `pnpm vitest run usePackageCatalogOptions PackageForm BulkIntakeForm` | `pnpm dev` — open package intake form as guard | Revert hook extension + form diffs |
| 10 | Gates + review pipeline | N/A (verification only) | Full suites below | Full suites below | N/A |

Chain strategy: PR1 targets the `delinquency-restrictions` tracker branch; PR2–PR9 each target the immediately preceding PR's branch; only the tracker merges to `main`. PR1 and PR4b are near/at the 400-line budget on their own — if either exceeds it after apply, split further (e.g. PR1 into production-only + test-only slices) before requesting review.

## Phase 1: Foundation (Collections module)

- [x] 1.1 Create `backend/Modules/Collections/app/Support/DelinquencySettings.php` — slug constants (`RESTRICTIONS_ENABLED`, `MIN_OVERDUE_AMOUNT`, `BLOCK_PACKAGE_INTAKE`, `BLOCK_AMENITIES`, `BLOCK_VISITOR_ACCESS`, `NOTICE_TEXT`) + defaults + `all(): array`, mirroring `CollectionSettings`
- [x] 1.2 Create `backend/Modules/Collections/app/Exceptions/DelinquencyRestrictedException.php` — `forResident(int $overdueBalanceMinor, string $noticeText)`, `forGuard()` (no money arg, non-configurable message constant), self-rendering `render(): JsonResponse` → 403 `code: DELINQUENT_RESTRICTED`
- [x] 1.3 Create `backend/Modules/Collections/app/Services/DelinquencyRestrictionService.php` — `overdueBalanceFor(unitId)` reusing `ChargeStatusService::effectiveExpected()`; `isRestricted`/`assertNotRestricted`; `stateForUnits(unitIds)` **per-unit, never ANY-aggregate**; `restrictedUnitIds(feature)` batch (≤3 queries, `unit_id` in the select list as groupBy key); private memoized `settings()` (one `whereIn` over the 6 slugs)
- [x] 1.4 Register the service with `$this->app->scoped(DelinquencyRestrictionService::class, ...)` in `CollectionsServiceProvider` — never `singleton()`, never plain default binding
- [x] 1.5 Test: rule matrix in `DelinquencyRestrictionServiceTest` — master off → never blocks; OVERDUE below threshold → no; at/above → yes; `min_overdue_amount = 0` → any OVERDUE unit blocked; **`PARTIAL` within term → NOT blocked** even with large unpaid remainder
- [x] 1.6 Test: `stateForUnits` multi-unit precision — resident with unit A current + unit B OVERDUE returns A unrestricted / B restricted (the gate-caught bug this design fixes)
- [x] 1.7 Test: `DelinquencyRestrictedException` payload split — `forGuard()` has no amount, no `notice_text`, none of "adeudo"/"moroso"/"deuda"; `forResident()` includes both `overdue_balance` (TenantMoney) and `notice_text`

## Phase 2: Persistence (seeder + backfill)

- [x] 2.1 Modify `Modules/Collections/database/seeders/CollectionsDatabaseSeeder.php` — add the six `delinquency.*` rows using `Setting::withTrashed()` create/restore (NOT `firstOrCreate`, the `(tenant_id, slug)` index is not partial)
- [x] 2.2 Create `Modules/Collections/app/Console/Commands/BackfillDelinquencySettingsCommand.php` cloning `BackfillCollectionSettingsCommand` — iterate tenants, `tenancy()->initialize`, seed only missing rows, `tenancy()->end()`
- [x] 2.3 Test: seeder idempotent including soft-deleted-row restore path; backfill only seeds missing rows and is tenant-scoped

## Phase 3: Config API

- [x] 3.1 Modify `Modules/Configuration/app/Http/Controllers/ConfigurationController.php` — add `getDelinquencySettings()` (flat keys; `min_overdue_amount` serialized via `TenantMoney::make()`, others raw) and `updateDelinquencySettings()` (`$request->only()` diff-only write, `sometimes` validation per design §6.1)
- [x] 3.2 Modify `Modules/Configuration/routes/api.php` — `GET`/`PUT config/delinquency-settings` gated `read.configuration`/`update.configuration`
- [x] 3.3 Test: GET returns TenantMoney for `min_overdue_amount`; PUT persists only sent keys (diff-only save, `5000` pesos in the request → `500000` stored); validation rejects negative amount and `notice_text` over 500 chars

## Phase 4: Hooks (five service-layer guards)

- [x] 4.1 Modify `Modules/AmenityReservations/app/Services/ReservationService.php::create()` — `assertNotRestricted($data['unit_id'], FEATURE_AMENITIES)` immediately after `authorizeUnitMembership()` (~L992)
- [x] 4.2 Modify `Modules/Access/app/Services/AccessService.php::generateQr()` — assert as the first business rule, feature `visitor_access`
- [x] 4.3 Modify `Modules/Access/app/Http/Controllers/VisitorController.php::approve()`/`reject()` — assert AFTER the existing `authorizeUnitMembership()` (L216/L233), on `$visitor->unit_id`, so a non-member still gets the plain `AuthorizationException`
- [x] 4.4 Modify `Modules/Package/app/Services/PackageService.php::register()` — assert as the first statement inside `DB::transaction`, before `persistWithUniquePickupCode()`
- [x] 4.5 Modify `Modules/Package/app/Services/PackageService.php::batchRegister()` — pre-transaction intersect of `array_unique(array_column($rows,'unit_id'))` against `restrictedUnitIds(FEATURE_PACKAGE_INTAKE)`, fail fast with `forGuard()` before opening the outer transaction
- [x] 4.6 Test: one 403 `DELINQUENT_RESTRICTED` per hook — reservation create, `POST /visitor`, `approve`, `reject`, `register`, `batchRegister`
- [x] 4.7 Test — never-blocked regression (do NOT let a future change break these): `GET /access/my-qr` → 200, `GET /access/my-log` → 200, `PackageService::handover`/`verifyCode` of an already-registered package → 200, amenity catalog/detail/availability browse → 200, `VisitorController::cancelQr` untouched — all for a delinquent-above-threshold unit
- [x] 4.8 Test — privacy: `package_intake` 403 payload has no amount key and none of "adeudo"/"moroso"/"deuda"; `GET /package/restricted-units` as a resident → 403
- [x] 4.9 Test — tenant isolation: tenant A `restrictions_enabled=true` + delinquent unit never restricts a same-state unit in tenant B (R-DELQ-10)
- [x] 4.10 Test — accepted board-bypass (pin, do NOT "fix"): a board/admin token reserving for a restricted foreign unit gets `forResident(...)` carrying that unit's balance, per design §10 ADR
- [x] 4.11 Test — immediate unblock: a payment that drops overdue balance below threshold unblocks a guarded action attempted right after commit, same transaction, no separate recompute (R-DELQ-9)
- [x] 4.12 Test — money rounding: `min_overdue_amount = 500000` blocks a unit with balance `500000` and `499999` but not below; `5000` pesos submitted from the frontend persists as `500000` minor units (cross-check with 3.3)

## Phase 5: Resident endpoint

- [x] 5.1 Create `Modules/Collections/app/Http/Controllers/DelinquencyRestrictionController.php` — uses `ScopesToResidentUnits::ownUnitIds()`, returns `DelinquencyRestrictionService::stateForUnits()` in the §6.2 per-unit JSON shape (no `package_intake` key)
- [x] 5.2 Modify `Modules/Collections/routes/api.php` — register `GET my-restrictions` under `['auth:sanctum','initializeTenancy']`, no ability gate (own-unit data only)
- [x] 5.3 Test: payload shape matches §6.2 (per-unit array, `overdue_balance` present only on restricted units, own-units-only scoping)

## Phase 6: Guard endpoint

- [x] 6.1 Modify `Modules/Package/app/Http/Controllers/PackageController.php` — add `restrictedUnits()`: `$this->authorize('create.package')` then `{unit_ids: [...]}` only, no amounts/statuses/names
- [x] 6.2 Modify `Modules/Package/routes/api.php` — register `GET package/restricted-units` **before** `Orion::resource('package', ...)` (route-order gotcha)
- [x] 6.3 Test: response is unit ids only; resident (no `create.package`) → 403; confirm the endpoint is never reachable through `/selects`

## Phase 7: Frontend config

- [x] 7.1 Create `frontend/src/modules/configuration/components/DelinquencyConfigSection.tsx` — `PackageConfigSection` pattern, `useSectionEdits`, diff-only PUT, money field as `<Input type="number" step="0.01">` converted via `shared/utils/money.ts` (pesos ↔ minor units)
- [x] 7.2 Modify `frontend/src/modules/configuration/configSections.tsx` — add `{ key: 'delinquency', label: 'Morosidad', icon: ShieldBan, ... }` entry
- [x] 7.3 Test: PUT sends only edited keys; `5000` pesos round-trips to `500000` minor units on save and back to `5000` on load; UI copy is Mexican Spanish (tú)

## Phase 8: Frontend resident

- [x] 8.1 Create `frontend/src/modules/collections/hooks/useMyRestrictions.ts` — `useQuery({queryKey:['my-restrictions'], staleTime: 60_000})` + derived `unitState(unitId)` lookup; mounted only inside components active when the action starts
- [x] 8.2 Modify `frontend/src/modules/reservation/components/ReserveAmenityWizard.tsx` — notice panel gated on the SELECTED unit at the picker (~L52-77); "Reservar" stays visible and explicitly NOT `disabled`; wizard always reaches the picker
- [x] 8.3 Modify `frontend/src/modules/access/components/VisitorFormModal.tsx` — same treatment on the `UnitCombobox` selection (~L140-146); "Registrar visita" stays visible and explicitly NOT `disabled`
- [x] 8.4 Test: `useMyRestrictions` not called at list/page scope (infra-cost guard); both buttons render enabled under restriction; notice appears only after selecting a restricted unit; multi-unit resident (A current, B OVERDUE) sees the notice only when B is selected

## Phase 9: Frontend guard

- [x] 9.1 Modify `frontend/src/modules/package/hooks/usePackageCatalogOptions.ts` — add `restrictedUnitIds: Set<string>` fed by `useQuery({queryKey:['package-restricted-units']})`, `enabled` gated on the existing lazy `enableUnits()` trigger
- [x] 9.2 Modify `frontend/src/modules/package/PackageForm.tsx` and `components/BulkIntakeForm.tsx` — subtle inline note "Esta unidad no puede recibir paquetes." under the unit field; submit blocked per restricted row/unit; unit stays selectable in the dropdown
- [x] 9.3 Test: restricted-units query fires only on dropdown open, never on list mount; note renders only for a restricted selection; submit stays blocked while any restricted row remains (batch form)

## Phase 10: Gates

- [ ] 10.1 `vendor/bin/pint --dirty` on all touched backend files
- [ ] 10.2 `php artisan test` — host php, **single process** (shared `testing` DB `migrate:fresh` conflict if run concurrently)
- [ ] 10.3 `pnpm lint` && `pnpm build` && `pnpm test`
- [ ] 10.4 `/review-stack` (`@laravel-architecture-reviewer`, `@react-frontend-reviewer`, `@integration-reviewer`, `@tenancy-reviewer`) + `@infra-cost-reviewer` (verify `useMyRestrictions`/restricted-units stay lazy, no eager catalog loads)
