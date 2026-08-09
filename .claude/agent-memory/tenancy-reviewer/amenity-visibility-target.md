---
name: amenity-visibility-target
description: Amenity read-target (A1+A2+B) isolation review — core whereIn boundary SAFE; WARNING open flank via amenity_slot/amenity_blackout sibling resources
metadata:
  type: project
---

Reviewed 2026-07-11, uncommitted change `amenity-visibility-target` (slices A1+A2+B) on branch `feat/amenity-reservations`. Adds `amenity.target_type`/`target_selector` so residents only SEE/reserve amenities whose target resolves to include one of their active units. Continues [[amenity-reservations-s9-s11]] / [[amenity-reservations-module-isolation]].

**Core boundary is SOUND (cross-tenant SAFE):**
- `AmenityVisibilityService`: `Amenity::query()`, `UnitUser::where('user_id')`, and `TargetResolver::resolveUnitIds()` (→ `Unit::query()`) are ALL tenant-scoped (`BelongsToTenant` on Amenity, UnitUser, Unit — grep-verified). Foreign `target_selector` ids resolve to zero units (billableQuery is tenant-scoped), Chat-parity claim holds — no cross-tenant leak.
- Bypass set `null || tokenCan('*') || tokenCan('update.amenity')` is EXACT ChatChannelController parity (`update.chat_channel` → `update.amenity`). Residents lack `update.amenity` (board/admin-only) so they can't hit the bypass. `null`-user branch is defensive dead code behind `auth:sanctum`.
- Create path `authorizeAmenityTarget` is correctly NO-bypass (applies to managers too — a booking for an out-of-target unit is a mistake regardless of role, per design.md AD#3). `$amenity` loaded via tenant-scoped `Amenity::whereKey()->lockForUpdate()->firstOrFail()`.
- Fail-closed verified: unknown target_type → `continue`/`return false` (never resolves → never throws → hidden); NULL target_type → `?? 'all'` → visible (matches NOT NULL default 'all' = public); empty `accessibleAmenityIds` → `whereIn('id', [])` = WHERE 0=1 → nothing (not everything). All 4 primary seams closed: index/show (buildIndex/ShowFetchQuery→whereIn), availability (explicit userCanSeeAmenity→404), create (unitInAmenityTarget→422).

**WARNING — open flank (the "5th seam"), NOT closed by this change:**
`AmenitySlotController` + `AmenityBlackoutController` both use `$keyForPermission = 'amenity'` (residents HOLD `read.amenity`, RolePermissionTableSeeder line ~562), are FULL Orion resources (index/show exposed, no `->only()`), have NO buildIndexFetchQuery/scope override, `filterableBy(['amenity_id'])`, and `includes(['amenity'])`. So a target-excluded resident can still:
- `GET /amenity_slot?include=amenity` (or `/amenity_blackout?include=amenity`) → every slot/blackout in the tenant with its FULL parent amenity hydrated → complete records + schedule of amenities their target HIDES. Orion does NOT re-apply AmenityController's scope on the eager-loaded relation (global TenantScope still applies → within-tenant only, NOT cross-tenant).
- `GET /amenity_slot?filter[amenity_id]=<hidden-id>` → confirms existence + reveals schedule of a specific hidden amenity.
This defeats the A2 boundary for slots/blackouts. Within-tenant visibility bypass (tables ARE `BelongsToTenant`), so WARNING not CRITICAL. FIX: add `buildIndexFetchQuery`/`buildShowFetchQuery` to both sibling controllers scoping `whereIn('amenity_id', accessibleAmenityIds(...))` for non-bypass users (same bypass set as AmenityController) — a slot row only survives if its amenity_id is accessible, which also neutralizes `?include=amenity` and `?filter[amenity_id]`.
