# Proposal: Trial Usage Quotas

> Evidence base: approved plan `~/.claude/plans/puedes-implementar-este-plan-sorted-moon.md` (Change B), verified against code. Depends on sibling `stripe-native-subscription-controls` (implemented, uncommitted).

## Intent

The only usage cap today is units — via `billing_package.suggested_unit_max` (`unit_limit` is a dead column). Nothing limits amenity reservations or visit registrations, and no usage-counter table exists, so a trial cluster can operate at full scale forever. Product goal: "during the trial, this cluster may register X units, make Y reservations and register Z visits", with `-1` = unlimited.

**Locked user decisions (do not relitigate):** quotas are mixed by resource — units count LIVE rows (deleting frees quota); reservations and visits ACCUMULATE lifetime consumption (deleting does NOT refund — otherwise delete/recreate stretches the trial forever). "One access" = registering a visit (resident generates the QR); later physical entries/exits are free. `-1` = unlimited.

## Scope

### In Scope

- New central catalog `plan_quota` (`billing_package_id` nullable = all plans, `scope` trial|active, `resource_key`, `limit_value`, `-1` unlimited; most-specific row wins). Postgres trap: TWO partial unique indexes (`WHERE billing_package_id IS NULL` / `IS NOT NULL`) — a plain unique allows duplicate global rows.
- New counter `tenant_quota_usage` (`tenant_id`, `resource_key`, `used_count`) for accumulating resources only, modeled on `receipt_sequence`/`complaint_sequence` (partial unique index, `lockForUpdate()`). Increment INSIDE the same transaction that creates the resource.
- `QuotaService` + `QuotaCheck` DTO in `Modules/SubscriptionPlan/app/Services/` (module keeps DTOs beside services), cloning `UnitLimitService`'s `check*/assert*/build*Message` shape and its fail-open criterion (no tenancy / unresolvable quota → allow). For `unit`: effective cap = `min(plan band, trial quota)`, delegating the band to `UnitLimitService`. Trial/active scope read from Change A's state service — never re-derived.
- `QuotaExceededException` in `Modules/SubscriptionPlan/app/Exceptions/`: `final`, `ShouldntReport`, `render()` → 422, stable code, payload `{resource, limit, used, scope, plan_name}`.
- Wire the three EXISTING hook points (DelinquencyRestrictionService injected-guard precedent): `UnitObserver` `creating()`/`restoring()`; `ReservationService.php:115` (insert `:135`, same transaction); `AccessService.php:150` `generateQr` (insert `:161`). `requestEntry()` (`:381`) explicitly costs NO quota (guard-initiated).
- Idempotent-upsert seeding of trial rows beside `PlanModuleMatrixSeeder` — `start.sh:419` reruns `db:seed` every deploy (prior incident: a seeder nulled `stripe_price_id`).
- Expose remaining quota in `GET /config/subscription`; intercept the new code in `apiClient.ts` (existing business-code seam).
- **Fix pre-existing bug**: backend emits `UNIT_LIMIT_EXCEEDED`, `UnitForm.tsx:188` compares `'unit_limit_reached'` — the upgrade dialog never opens; new quotas would inherit it.

### Out of Scope

- Admin screen to edit quotas (v1 seeds; table stays ready) · per-period/monthly-reset quotas (counter is lifetime) · quotas on Package/Complaint/Chat/Announcement (future hook points only) · commits/branches/push/worktrees (HARD).

## Capabilities

> `openspec/specs/` does not exist; capabilities live in change folders.

### New Capabilities

- `plan-quota-catalog`: quota definition rows + most-specific resolution + `-1` encoding (QuotaService is the ONLY place knowing both `-1` and legacy `null` unlimited encodings).
- `quota-consumption-tracking`: lifetime per-tenant counter, transactional increment, no refund on delete.
- `quota-enforcement`: guard service + 422 exception + the three hooks; fail-open.
- `quota-visibility`: remaining quota in `/config/subscription` + frontend code interception (no dedicated screen).

### Modified Capabilities

- `unit-limit-enforcement` (archived 2026-07-15): unit cap becomes `min(plan band, trial quota)`; frontend error-code mismatch fixed so the upgrade dialog opens.

## Approach

One guard, two encodings hidden behind it. Copy proven module patterns (`UnitLimitService` DTO/exception shape, sequence-table locking, injected-guard call sites) instead of inventing infrastructure; touch only the three choke points that already exist. Spanish (MX) user-facing copy via `build*Message()`.

## Affected Areas

| Area | Impact |
|------|--------|
| `Modules/SubscriptionPlan` migrations + `Services/QuotaService|QuotaCheck` + `Exceptions/QuotaExceededException` + seeder | New |
| `Modules/Housing/app/Observers/UnitObserver.php` (:31, :59) | Modified |
| `Modules/AmenityReservations/app/Services/ReservationService.php` (:115) | Modified |
| `Modules/Access/app/Services/AccessService.php` (:150) | Modified |
| `/config/subscription` payload; `frontend/src/shared/api/apiClient.ts`; `frontend/src/modules/unit/UnitForm.tsx:188` | Modified |
| Tests: QuotaService unit + one feature per hook | New |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Quotas block real daily operations (gate can't register visits) | Critical | fail-open is DELIBERATE — never harden; seed values reviewed; `-1` path tested |
| Base is uncommitted WIP atop `stripe-native-subscription-controls`; no rollback commit | High | surgical hunk-level edits; never revert whole files |
| `AccessService` has FIVE `access_log` creation sites | Medium | quota scoped to `visitor` creation in `generateQr` only — single choke point |
| Seeder rerun on every deploy destroys data | Medium | idempotent upsert; never null existing columns |
| Worktree `backend/.claude/worktrees/fix+billing-base-scope-menu/` | Low | never touch; exclude from searches |

## Rollback Plan

Nothing committed: discard this change's hunks by hunk (files are shared with sibling WIP). New tables/seeded rows are additive — dropping the two migrations removes them; no existing data is mutated except the `UnitForm.tsx` string fix.

## Dependencies

- `stripe-native-subscription-controls` (uncommitted, verified): sole source of the trial/active signal.
- Baselines re-measured BEFORE work, single test process: backend `--filter=Billing` (269) plus `UnitLimit`, `Housing`, `AmenityReservations`, `Access`; frontend `tsc -b` + lint + vitest (~756).

## Success Criteria

- [ ] Trial cluster hitting a quota gets 422 with stable code + Spanish MX message; UI opens the upgrade path (including the fixed unit dialog).
- [ ] Deleting a reservation/visit does NOT refund quota; deleting a unit DOES free it.
- [ ] Rolled-back reservation transaction consumes zero quota.
- [ ] `-1` removes the limit; missing/unresolvable quota fails OPEN.
- [ ] `requestEntry()` never consumes quota.
- [ ] Seeder reruns are byte-idempotent (no data destroyed).
- [ ] All named backend suites ≥ baseline; frontend checks clean.
