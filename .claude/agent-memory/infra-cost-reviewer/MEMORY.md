# infra-cost-reviewer — MEMORY

Project goal: minimize backend/infra spend. Cost = requests/queries/rows that fire before needed,
multiplied across tenants/rows/mounts. Read topic files for detail; this is the index.

---

## Conventions

- [Lazy catalog loading (HARD)](lazy-catalog-convention.md) — form catalogs load-on-form-open; filter catalogs load-on-dropdown via `useLazyCatalogs`; never on list mount. Rules-of-hooks trap: `if (!isOpen) return null` cannot follow `useCatalogs` in the same component.

---

## Package module findings (2026-06-29)

Full detail in [package-module-findings.md](package-module-findings.md). Summary:

**WU-1** (FIXED in WU-2)
- CRITICAL → FIXED: `Setting::getValue` uncached in `batchRegister` — 2N queries per N packages. Fixed by `getSetting()` instance memo on `PackageService`.
- WARNING → FIXED: Double UnitUser query per resident list request. Fixed by `$this->callerActiveUnitIds ??=` memo on controller.

**WU-2** (open)
- WARNING: `verifyPickupCode` over-fetches `carrier`+`status` on matched package (never read; 2 extra SELECTs per QR scan × every guard × tenant).
- WARNING: `handover` — N save() calls, no `max:20` bound on `package_ids`.
- SUGGESTION: `myPackages` — unbounded `->get()` with 5 eager-loaded relations including `media`.

**WU-3** (open)
- WARNING: `Setting::getValue` × 1-2 in `updatePackageSettings` cross-field validator instead of one batched `whereIn`. Admin-only, low-frequency.
- CLEAN: `getPackageSettings` uses batched `whereIn` — ONE SELECT for all 7 slugs. Do NOT re-flag.

**WU-4** (open — 2026-06-29)
- WARNING: `ProcessPackageRetentionCommand::processTenant()` calls `Setting::getValue` twice per tenant (lines 68-69) — 2M queries for M tenants. Fix: `Setting::whereIn([...])->pluck('value','slug')`.
- WARNING: Unbounded `->get()` on READY packages per tenant (line 72) — loads all into PHP memory. Fix: `->chunk(200, fn($pkgs) => ...)`. (`cursor()` incompatible with `with()`.)
- SUGGESTION: `unitResidentUserIds()` not deduped by `unit_id` in the reminder loop (line 119) — one `unit_user` SELECT per reminder-eligible package. Fix: `$cache[$unitId] ??= $this->unitResidentUserIds($unitId)`.
- SUGGESTION: `app(PackageService::class)` resolved inside foreach (line 89). Resolve once before the loop.

**WU-5** (reviewed 2026-06-29 — SHIP)
- SUGGESTION: `export()` line 63 — `->with(['status'])` on a query already filtered to `PackageStatus::READY`. Fires 1 extra SELECT against `package_status` returning 1 row per export request. Fix: resolve `$readyLabel` once outside the map. Spec (§5.2.2) explicitly listed it; minor over-spec cleanup.
- SUGGESTION: `pendingCount()` lines 106-109 — 2 separate COUNTs (round-trips). Spec §5.1.2 chose this deliberately for SQLite portability in tests. Defer merge into conditional aggregation until tests abandon SQLite. Endpoint gated at `read.package` (resident-level); highest-frequency of WU-5 endpoints.
- CLEAN: `aging()` unbounded `->get()` is correct for a report endpoint (all data needed for bucketing). NOT a flag.
- CLEAN: `pending-count` 2 COUNTs are spec-mandated and lightweight (no row loading). NOT a flag.
- CLEAN: `unit.unitType` + `unit.tower` eager-loads in `aging()` — NEEDED by `full_identifier` accessor (same known false positive as WU-2). NOT a flag.

---

---

## WU-6 Catalog admin UI (reviewed 2026-06-29 — SHIP)

- CLEAN: `PackageCarrierListPage` and `PackageTypeListPage` both use `CatalogCrudModule`. No catalog-type form fields → `useCatalogs({})` is gated by `Object.keys({}).length > 0` in `useCatalogs.ts:133` → `isEnabled = false` → ZERO `POST /selects` calls for these pages.
- CLEAN: `CatalogFormModal` (lines 104-115 of `CatalogCrudModule.tsx`) has NO hooks and uses `if (!isOpen) return null` before delegating to `CatalogFormSheet`. This is the gold-standard lazy form pattern. `CatalogFormSheet` (which has `useCatalogs`) only mounts when the modal opens.
- CLEAN: `useBaseList` with `autoInit: true` uses `useInitOnce` (line 250) — zero double-fetch risk from strict-mode re-mounts.
- CLEAN: `PackageStatusBadge` is purely presentational — no fetch.
- CLEAN: `App.tsx` additions use `lazy()` → modules not loaded until the user navigates to the route.
- CLEAN: `initialSearch` for both list pages has NO `includes` → no eager relation loading on mount.

**Pattern reaffirmed:** `CatalogCrudModule` is safe to use for catalogs without catalog-type form fields — the `useCatalogs({})` gate prevents the request from firing even when `CatalogFormSheet` mounts. The `CatalogFormModal` null-guard prevents the hook-bearing child from mounting while the modal is closed.

---

## WU-7 Frontend core (reviewed 2026-06-29 — fix-then-ship)

- WARNING: `schema.ts:130-133` — `packagePickupSchema.package_ids` missing `.max(20)`. Spec tasks.md §2.2.1 explicitly required this as a cost-protection bound (N saves + 2N media copies per handover). Backend FormRequest enforces max:20 before executing saves, so the actual cost is a single failed request, but the spec mandates frontend mirror this validation. Fix: `.max(20, 'Máximo 20 paquetes por entrega')`.
- SUGGESTION: `PackageConfigSection.tsx:47-50` — `GET /config/package-settings` has no `staleTime` (defaults 0ms). Sibling consumer `PackagePickupModal` sets `staleTime: 60_000` on same queryKey `['config', 'package-settings']` — TanStack Query uses minimum staleTime of active observers; the 0ms wins. Fix: add `staleTime: 60_000`.
- SUGGESTION: `MyPackagesPage.tsx:185-188` — `GET /my-packages` has no `staleTime` and no `refetchOnWindowFocus: false`. Default staleTime=0 + focus refetch multiplies across all residents on this page. Fix: `staleTime: 30_000, refetchOnWindowFocus: false`.
- SUGGESTION: `PackageListPage.tsx:203-207` — `agingQuery` has no `staleTime`. Aging endpoint loads ALL ready packages with eager relations; every tab re-activation refetches. Fix: `staleTime: 60_000`.

**Clean patterns confirmed in WU-7:**
- `PackageListPage` filter catalogs: `useLazyCatalogs` + `useMemo` + `onFirstOpen` — canonical lazy filter pattern ✓
- `PackageForm` (intake form): `useLazyCatalogs` inside `SingleIntakeForm`/`BulkIntakeForm` which live inside Radix `<DialogContent>` — NOT mounted when dialog closed (double-protection: Radix unmount + lazy enabled state) ✓
- `PackagePickupModal`: `policyQuery` with `enabled: isOpen` — correct lazy gate for always-mounted modal component ✓
- `agingQuery` with `enabled: activeTab === 'aging'` — correct lazy tab pattern ✓
- `PendingPackagesWidget`: `staleTime: 5 * 60_000`, `enabled: canRead`, no polling ✓
- `App.tsx` package routes all use `lazy()` ✓

---

## AmenityReservations module (reviewed 2026-07-06, S3–S5 domain phase, uncommitted on feat/amenity-reservations)

- WARNING: `AmenityAvailabilityService::forRange()` (`Modules/AmenityReservations/app/Services/AmenityAvailabilityService.php:55-137`) loops `for ($offset = 0; $offset < $totalDays; $offset++)` and calls `buildBySlotDay`/`buildByDayDay` per day, each of which fires its OWN occupancy query (`occupancyBySlot()` / `hasHoldingByDayReservation()`) when the day is open. Bounded by `MAX_RANGE_DAYS = 31`, and each individual query IS indexed (`amenity_reservation_amenity_date_idx`, `amenity_reservation_slot_date_idx`) — so this is N bounded+indexed round-trips, not unbounded N+1. Still: a calendar-style "next 30 days" availability request (the exact use case the range param + 31-day cap were built for) fires up to 31 separate queries per request × every resident opening the booking calendar × every amenity × every tenant. Fix: batch ONE query across `[start,end]` grouped by `(reservation_date, slot_id)` (by_slot) or `(reservation_date)` (by_day) BEFORE the day loop, then look up per day from the resulting map in PHP — turns N queries into 1 regardless of range length. Not yet fixed as of this review; flag again if seen unfixed in a later slice.
- CLEAN: within a single day, `occupancyBySlot()` (line 182-198) is a proper single grouped query (`selectRaw('slot_id, COUNT(*) as occupied')->groupBy('slot_id')`) covering ALL slots of that day — no per-slot loop. This is the correct set-based pattern; don't confuse with the per-DAY loop above.
- CLEAN: `structuralAvailability()` (blackout/weekday checks) operates on `$amenity->loadMissing(['slots','blackouts'])` — ONE eager-load for the whole request, then pure in-memory collection checks per day. Zero extra queries per day for structural rules.
- CLEAN: `buildByDayDay`/`buildBySlotDay` use `$open && ...` short-circuit — the occupancy query is skipped entirely on closed/blacked-out/out-of-service days, not wasted.
- CLEAN: `availability()` endpoint (`AmenityController.php:45-63`) only `loadMissing` on the ONE requested amenity (not a list), date range hard-capped at 31 days — no unbounded scan.
- CLEAN: `AmenityController::includes()` / `AmenitySlotController::includes()` / `AmenityBlackoutController::includes()` are Orion whitelists (`reservation_mode`, `payment_timing`, `bank_account`, `slots`, `blackouts`, `media` / `amenity`) — confirmed NOT auto-eager-loaded, consistent with the known false positive below.
- SUGGESTION: `EnsureAmenityReservationsEnabled::handle()` (`app/Http/Middleware/EnsureAmenityReservationsEnabled.php:51`) calls `Setting::getValue()` — an uncached query, indexed (`unique(tenant_id, slug)`) — on EVERY request to any amenity/amenity_slot/amenity_blackout/availability route, for every tenant. This is a fixed +1 round-trip per request (not a row/N multiplier). It exactly mirrors the project's EXISTING `VerifyModuleEnabled` + `ModuleTenantService::isEnabledByPermissionEntity` pattern (2 uncached queries: Module pluck + module_tenant exists) — so this is consistent with established precedent, not a regression introduced by this module. Worth a future centralized fix (a cached kill-switch helper shared by all these module-toggle middlewares) rather than a per-module patch, but do not block this PR on it alone.

---

## Known false positives — do NOT flag these

- Orion `includes()` array in controller does NOT auto-load — client must request it.
- `unit.unitType` + `unit.tower` in `verifyPickupCode` are NEEDED by `Unit::full_identifier` — not over-fetch.
- `saveQuietly()` in cron/command context is correct — bypasses GlobalObserver intentionally (no `auth()->user()`).
- `with(['carrier'])` in bulk queries = ONE Eloquent batch SELECT per tenant, not N+1.
- Per-package `returnPackage()` calls in cron intentionally NOT batched — batching loses GlobalObserver audit stamps.
- `NotifiesResidents::recordInApp` uses bulk `insertOrIgnore` — single INSERT for all recipients.
- `PackageServiceProvider` using `$this->app->booted()` instead of `callAfterResolving` — both work, no cost difference.
- Per-request uncached `Setting::getValue()` (or equivalent) inside a route-level "module enabled" middleware is an ESTABLISHED project pattern (`VerifyModuleEnabled`, `EnsureAmenityReservationsEnabled`) — it's a fixed +1 query per request (indexed lookup), not a row multiplier. Don't flag as CRITICAL; a SUGGESTION for a shared cached-toggle helper is enough.

## Delinquency filters (reviewed 2026-07-19)

Detail: [delinquency-filters-review.md](delinquency-filters-review.md). Frontend lazy/debounce claims VERIFIED true. Main open: WARNING PHP-side aggregation over unbounded charge load in `delinquencyRows()`; SUGGESTION dead `charge_status` eager-load. Global queryClient has staleTime 30s + no focus refetch — check it before flagging missing staleTime.

## Complaint (Denuncias) module (reviewed 2026-07-19 — near-clean, SHIP with notes)

Frontend CLEAN across the board: `ComplaintListPage` = canonical lazy filters (4× `useLazyCatalogs` + `MultiSelectFilter onFirstOpen`); `ComplaintForm` always-mounted at list scope but ALL its catalogs are `useLazyCatalogs` gated on dropdown-open → zero requests while closed (lazier than the form-open gold standard; acceptable variant, don't flag); detail `TransitionControl` lazy status catalog; `AssignmentControl` uses server-side `UserResource.searchByTerm` (no full USER catalog); `ConfigurationPage` uses Radix Tabs which UNMOUNT inactive tabs → `ComplaintConfigSection` GET only fires when its tab opens (better than Package-era assumption — verify Tabs pattern before flagging config sections); App.tsx routes lazy.

Backend mostly clean: `ComplaintService` memoizes settings (`$settingCache ??=`, WU-2 lesson applied); `getComplaintSettings` batched `whereIn`; catalog models correctly on `CentralCatalogModel`/`TenantCatalogModel`; `scopeVisibleTo` = ONE upfront `unit_user.user_id`-indexed pluck + whereNotIn, NOT a per-row correlated subquery (good pattern, don't flag); folio via row-locked `complaint_sequence` (no MAX scan on hot path); migrations index category/priority/against_unit + composite (status, created_at) + partial unique (tenant_id, folio).

Open findings (2026-07-19):
- WARNING: `ComplaintController::messages()` line 151 — unbounded `->get()` with `author`+`media` on every detail-page view; threads grow forever. Fix: cap/paginate.
- SUGGESTION: no index on `complaint.assigned_user_id` (in `filterableBy`, UI filter) nor `reporter_user_id` (visibleTo OR-branch). Defer to @database-architect.
- SUGGESTION: `notifyEvent` fan-out runs INSIDE `register()`'s transaction while the per-tenant `complaint_sequence` row lock is held → registrations serialize on notification resolution. Move notify after commit.
- NOTE: `unit_user.unit_id` has FK but NO index (pre-existing Housing schema; Postgres doesn't auto-index FKs) — Complaint's `excludedUserIdsFor` adds a new query path on it.

Reaffirmed: /selects `$modelMapper` additions cost nothing until requested; `updateOrCreate`-per-slug in admin-only settings PUT (≤5 keys) not worth flagging.

## Signup con plan+tarjeta obligatorios y trial de 30 días (2026-08-07)

Detalle: [billing-trial-signup-2026-08.md](billing-trial-signup-2026-08.md). Presupuesto real de
`EnsureSubscriptionActive` = **3 queries** por request autenticado no-allowlisted, **0 extra para
un dueño que paga**; la allowlist corta ANTES de auth (0 queries) y cubre toda /select-plan.
`ownerHasTrialingStripeSubscription()` ya NO existe. Duplicado PRE-EXISTENTE: `ActiveTenantResolver`
corre 2× por request (middleware global + tenancy) → 1 SELECT `user_tenant` de más siempre; memoizar
en `$request->attributes`, NUNCA en el servicio (Octane). `is_purchasable`, `VerifyBillingCatalog`,
el sondeo acotado de `SuccessView` y el landing: LIMPIOS, no re-marcar. Abierto: `/billing/plan-quote`
= ~7 queries sin caché (+N+1 en `activePrice`) y ahora TODO registro pasa por ahí.

## Topic files recovered 2026-07-20 (from stray submodule `.claude/agent-memory` copies)

From `backend/`:
- [Billing public endpoints (Phase 1)](billing-public-endpoints.md) — catalog+quoter cost review; clean parts + the one waste (quote() ignores the cached catalog, runs 2 DB queries/call). Dated 2026-06-30.
- [Direct charge feature (Cobro directo)](direct-charge-feature.md) — frontend catalog loading is clean gold-standard; backend `ChargeController::direct()` re-loads relations already loaded by `PaymentService::register()`, none read by frontend. Dated 2026-07-03.
- [Amenity reservations feature (frontend)](amenity-reservations-frontend.md) — confirmed CLEAN gold-standard catalog/availability/calendar loading end-to-end; one WARNING (avoidable amenity.bank_account round-trip on admin "Registrar pago"). Dated 2026-07-07.
- [Delinquency restrictions](delinquency-restrictions.md) — settings batching/scoped() + all FE gating verified clean (incl. Radix tabs don't mount inactive sections); batch-precheck-then-per-row-assert N+1 in PackageService. Dated 2026-07-14.
- [Board member edit (frontend)](board-member-edit.md) — confirmed CLEAN gold-standard: lazy BOARD_ROLE catalog in conditionally-mounted form, UserCombobox hasOpened gate, bounded refresh per mutation. Dated 2026-07-19.

From `frontend/`:
- [Lazy-catalog loading convention](pattern-lazy-catalog-loading.md) — the 6 sanctioned wirings to keep POST /selects off list mount; shared hooks `useCatalogs(req,{enabled})` + `useLazyCatalogs(req)`
- [Lazy-catalog refactor confirmed clean (2026-06-29)](clean-lazy-catalog-refactor-2026-06.md) — 18 files + CatalogCrudModule verified: no /selects on list mount
- [SearchSelect migration non-regressing (2026-06-29)](clean-searchselect-migration-2026-06.md) — Select→SearchSelect swap kept every lazy gate; SearchSelect never fetches in client mode (no consumer passes loadOptions)
- [False positive: inline catalog request objects are NOT a refetch storm](fp-unstable-querykey-content-hash.md) — TanStack Query content-hashes query keys; new ref + same content = same hash = no refetch
- [Backend cost wins to prefer: aggregates over hasMany includes](pattern-backend-aggregate-vs-include.md) — Orion `aggregates:[{relation,type:'count'}]` (needs controller `aggregates()` whitelist) kills per-row N+1 for counts
- [Chat channel editor audit (2026-07-19)](chat-channel-editor-2026-07.md) — page-as-form eager catalogs SANCTIONED; open WARNING: copy-config inherits fat members include on direct URL; shared-queryKey cold-entry heuristic
