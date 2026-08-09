# Verification Report: Amenity Visibility Target

**Change**: `amenity-visibility-target`
**Mode**: Full spec-driven verification (proposal + specs + design + tasks all present)
**Verdict**: PASS WITH WARNINGS
**Date**: 2026-07-11

Executive summary: 0 CRITICAL, 1 WARNING, 1 SUGGESTION. All 4 slices (A1
inert layer, A2 read-scoping, B create-time enforcement, C frontend) plus the
tenancy-review fix (AmenitySlot/AmenityBlackout controller scoping) are
implemented in the working tree and pass. Backend `--filter=Amenity` = 196
passed / 746 assertions; frontend `pnpm test amenity` = 61 passed / 11 files;
`pnpm typecheck` clean; `pnpm build` succeeded; `pint --test --dirty` clean.
The one WARNING is a stale planning-artifact inconsistency (the Engram spec
snapshot #1541), NOT a code defect — shipped behavior matches the authoritative
openspec delta. Safe to archive after reconciling that snapshot.

## Task Completeness

| Slice | Scope | Tasks | Status |
|---|---|---|---|
| A1 | Migration + model + `AmenityVisibilityService` + FormRequest validation | A1.1–A1.4 + exit | Complete |
| A2 | `AmenityController` read scoping (index/show/availability) | A2.1–A2.3 + exit | Complete |
| B | `ReservationService` create-time target enforcement | B.1–B.3 + exit | Complete |
| C | Frontend admin target editing | C.1–C.4 + exit | Complete |
| Tenancy fix | `AmenitySlotController` + `AmenityBlackoutController` FK scoping | (review follow-up) | Complete |

All task checkboxes in `tasks.md` are `[x] DONE` and match code state. Zero
unchecked implementation tasks.

## Build / Test / Format Evidence

| Command | Result |
|---|---|
| `php artisan test --compact --filter=Amenity` (host PHP, Postgres 127.0.0.1:5432) | 196 passed / 746 assertions, exit 0 |
| `pnpm test amenity` (frontend) | 11 files / 61 tests passed, exit 0 |
| `pnpm typecheck` | clean, 0 errors |
| `pnpm build` | succeeded (285 PWA precache entries), exit 0 |
| `vendor/bin/pint --test --dirty` | passed |

Frontend `pnpm lint` NOT re-run in full: known pre-existing `frontend/emails/*.tsx`
errors are unrelated to touched files; apply-progress already confirmed 0
errors/warnings on the 7 touched amenity files via targeted eslint.

## Spec Compliance Matrix (amenity-visibility capability)

| Requirement | Scenario | Evidence (code + passing test) | Status |
|---|---|---|---|
| Read-Target Columns and Default | Default amenity universally visible | Migration `2026_07_11_100000` NOT NULL default `'all'`; `test_default_all_target_is_visible_to_every_resident`, `test_create_for_all_target_succeeds_unaffected` | PASS |
| Target Membership Resolution | Multi-unit resident, one unit in-target | `AmenityVisibilityService::accessibleAmenityIds` intersects active `unit_user`; `test_multi_unit_resident_sees_amenity_when_only_one_unit_is_in_target` (service) + scoping-test twin | PASS |
| Target Membership Resolution | by_feature requires ALL features | Delegates to `TargetResolver::byFeature` (AND); `test_by_feature_requires_all_selected_features` | PASS |
| Manager Bypass | Admin sees out-of-target amenity | `scopeToAccessibleAmenities` short-circuits `tokenCan('*')`/`tokenCan('update.amenity')`; `test_update_amenity_holder_bypasses_the_target_and_sees_every_amenity` | PASS |
| Server-Side Enforcement | Out-of-target resident: absent index / 404 show / 404 availability | `buildIndexFetchQuery`/`buildShowFetchQuery` whereIn + `availability()` explicit 404 abort; `test_resident_out_of_target_cannot_see_the_amenity_anywhere` | PASS |
| Server-Side Enforcement | In-target resident sees all three | `test_resident_in_target_sees_the_amenity_in_index_show_and_availability` | PASS |
| Unknown Target Type Fails Closed | Invalid on write rejected 422 | `Rule::in(TargetResolver::TARGET_TYPES)` in both FormRequests; `test_invalid_target_type_returns_422` | PASS |
| Unknown Target Type Fails Closed | Persisted unknown hidden on read | Service `! in_array(...TARGET_TYPES) → continue`/`false` (no throw); `test_unknown_target_type_is_hidden_without_throwing` | PASS |
| Admin Target Editing | Set on create / narrow on edit round-trip | `AmenityTargetFields` + `AMENITY_TARGET_NAMES` + `buildAmenityFormDefaults` (shared `selectorIds`/`selectorString`) + `buildAmenityPayload` (`buildTargetSelector`); `schema.test.ts` + `buildAmenityPayload.test.ts` + `AmenityForm.test.tsx` | PASS |

## Spec Compliance Matrix (amenity-reservations delta)

| Requirement | Scenario | Evidence | Status |
|---|---|---|---|
| Authorization (MODIFIED) | Create in-target succeeds | `authorizeAmenityTarget` after `authorizeUnitMembership`; `test_create_for_in_target_unit_succeeds` | PASS |
| Authorization (MODIFIED) | Create out-of-target rejected, no row | `abort(422)` inside `create()` closure; `test_create_for_out_of_target_unit_by_resident_returns_422` (asserts 0 rows) | PASS |
| Authorization (MODIFIED) | Even a bypass holder cannot create out-of-target (NO manager bypass) | `authorizeAmenityTarget` has NO `tokenCan` check; `test_create_for_out_of_target_unit_by_admin_also_returns_422` uses board token, asserts 422 + 0 rows | PASS |
| Authorization (MODIFIED) | Resident cannot validate payment | Unchanged; existing treasurer-only gate | PASS (unchanged) |
| Target Narrowing Not Retroactive (ADDED) | Existing reservation survives narrowing | Read path (`ScopesToResidentUnits`) untouched; `test_narrowing_an_amenitys_target_does_not_retroactively_hide_an_existing_reservation` (reservation 200, amenity 404) | PASS |

## Design Coherence

Implementation matches design + the two corrections the tasks phase flagged:
- Create-time rejection uses `abort(422, ...)` inside the transaction closure
  (not a thrown `InvalidArgumentException`), correctly rendering 422 —
  `performStore()` has no try/catch. Verified in `ReservationService.php:1019-1024`.
- Batched resolution (Architecture Decision #1): `accessibleAmenityIds` groups
  amenities by `target_type|json(selector)` and calls `resolveUnitIds` once per
  distinct group, proven by `test_amenities_sharing_one_target_resolve_it_exactly_once`.
- The spec/design conflict on the create-time bypass is RESOLVED in the
  authoritative openspec delta: it now states the target check "has NO manager
  bypass ... applies to ALL callers." Shipped code (no bypass) matches it.

## Issues

### CRITICAL (blocks archive)
None.

### WARNING (non-blocking; reconcile at archive)
1. **Stale Engram spec snapshot #1541** (`sdd/amenity-visibility-target/spec`)
   still contains the OLD `amenity-reservations` scenario "Bypass holder creates
   regardless of target," which CONTRADICTS shipped behavior and the corrected
   authoritative openspec file
   (`specs/amenity-reservations/spec.md` → "Even a bypass holder cannot create
   for an out-of-target unit"). The code is correct; only the Engram planning
   snapshot is stale. Archive should not sync that scenario to main specs — use
   the openspec delta as source of truth and update/note the Engram snapshot.

### SUGGESTION (cosmetic, pre-existing, out of this change's file scope)
1. Shared `SingleTargetSelector.tsx`'s `SINGLE_TARGET_TYPE_HINTS.by_feature`
   reads "Con alguna de las características elegidas" (OR-flavored) while
   `by_feature` is AND/intersection (`TargetResolver.php`). This hint predates
   this change, is shared by Collections/Chat, has no prop override, and the
   actual selector logic is correct. One-line text fix whenever that shared
   component is next touched. Behavior is unaffected.

## Verdict

**PASS WITH WARNINGS.** Every spec requirement is implemented server-side and
covered by a test that passed at runtime. The single warning is a stale planning
artifact, not a code defect. Recommended next phase: `sdd-archive` (reconcile the
Engram spec snapshot's bypass scenario against the authoritative openspec delta
during the sync).
