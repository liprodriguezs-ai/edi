# Proposal: Amenity Visibility Target

## Intent

Residents currently see and can reserve EVERY amenity: `AmenityController` has no resident scoping, so an amenity meant for one tower/section is exposed to all. Give each Amenity a read-target (`target_type` + `target_selector`, same vocabulary as Collections/Chat) so residents only see and reserve amenities whose target resolves to include one of their active units — mirroring Chat's channel visibility.

## Scope

### In Scope
- Additive migration on `amenity`: `target_type` (string, default `'all'`) + `target_selector` (jsonb nullable, cast `'array'`), mirroring `chat_channel` columns, with `->comment()`. Module is shipped on main — new migration, not an edit.
- New `AmenityVisibilityService` (constructor-injects Collections `TargetResolver`), mirroring `ChatService::accessibleChannelIds()`/`matchesTarget()`. Short-circuit `'all'`/`null`/`'by_share'` to visible without a resolver query.
- Server-side enforcement at the three resident seams: new `buildIndexFetchQuery`/`buildShowFetchQuery` overrides in `AmenityController`; explicit check in the custom `availability()` route; new `authorizeAmenityTarget()` in `ReservationService::create()` (sibling to the unit-ownership check).
- Bypass: any user with `update.amenity` (admin + board roles) sees ALL amenities — mirror Chat's `scopeToAccessibleChannels`.
- FE: `SingleTargetSelector` in `AmenityForm`, reusing shared `buildTargetSelector()` + inverse-read seeding (mirror chat channel form); new catalogs added to the form's EXISTING `useCatalogs` call (no new eager load).

### Out of Scope
- Write-target (read/visibility only; no "who can post" concept).
- Changes to `ScopesToResidentUnits` (own-reservation scoping is a separate concern).
- Retroactive changes to existing reservations.
- Caching layer for `TargetResolver`.

## Capabilities

### New Capabilities
- `amenity-visibility`: amenity read-target columns, resident-facing server-side scoping (list/show/availability/reserve), manager bypass, admin target-editing UI.

### Modified Capabilities
- `amenity-reservations`: reservation create gains a rejection rule — the reservation's unit must fall within the amenity's read-target.

## Approach

Mirror Chat's proven channel-visibility pattern (design decides exact HOW). Out-of-target = FULLY HIDDEN, enforced server-side: absent from resident list, show → 404, availability → 404, reservation create → rejected. Default `'all'` on rollout = zero behavior change. Membership check = resolved target unit ids ∩ user's active `unit_user` rows. Note: `by_feature` is AND/intersection (unit has ALL selected features), not OR — carry into spec and UI copy.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/AmenityReservations/database/migrations/` | New | Add target columns to `amenity` |
| `backend/Modules/AmenityReservations/app/Models/Amenity.php` | Modified | fillable + casts |
| `backend/Modules/AmenityReservations/app/Services/AmenityVisibilityService.php` | New | Target-match logic via `TargetResolver` |
| `backend/Modules/AmenityReservations/app/Http/Controllers/AmenityController.php` | Modified | index/show scoping + availability check |
| `backend/Modules/AmenityReservations/app/Services/ReservationService.php` | Modified | `authorizeAmenityTarget()` on create |
| `frontend/src/modules/amenity/` | Modified | Target selector UI, payload builder, form defaults |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Per-list N resolver queries (one per non-`'all'` amenity) | Med | Short-circuit `all`/`null`; dedupe identical targets; amenity counts are small (Chat precedent); design picks cheapest check variant (`resolveUnitIds()` or resolve caller's units once) |
| Unknown `target_type`: `TargetResolver` throws vs Chat's silent hide | Low | Design decides explicitly; never silently expose/hide the wrong amenity |
| Custom `availability()` route bypasses Orion scoping | Med | Explicit in-route check; 404 per Collections' foreign-unit precedent (no info leak) |

## Rollback Plan

Additive and default-preserving: revert code; columns may remain (default `'all'` reproduces current behavior exactly) or drop via down migration. No data to unwind.

## Dependencies

- Collections `TargetResolver` (existing; cross-module injection, Chat precedent)
- Shared `SingleTargetSelector` + `buildTargetSelector()`/`selectorIds()` utils (existing)

## Success Criteria

- [ ] Out-of-target resident: amenity absent from `GET /amenity`, show → 404, availability → 404, reservation create rejected
- [ ] `update.amenity` holders see all amenities regardless of target
- [ ] Rollout default `'all'` → zero behavior change until an admin narrows a target
- [ ] Admin sets/edits the target in `AmenityForm`; selector round-trips on edit
- [ ] Backend tests and frontend build green
