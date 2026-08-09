# Design: Amenity Visibility Target

## Technical Approach

Mirror Chat's channel-visibility pattern (`ChatChannelController:49-57,176-188` + `ChatService::matchesTarget`) as a READ-ONLY target on `Amenity`: two new columns (`target_type`, `target_selector`), a new `AmenityVisibilityService` that maps targets to unit ids via Collections `TargetResolver`, and enforcement at all four resident seams — Orion index/show, the custom `availability()` route, and `ReservationService::create()`. Out-of-target = fully hidden (list-absent, show/availability 404, create 422). Default `'all'` = zero behavior change on rollout.

## Architecture Decisions

### Decision: Batched target resolution (NOT Chat's per-row resolve)

**Choice**: `accessibleAmenityIds()` resolves the resident's active unit ids ONCE (`UnitUser` where `user_id`, `end_date IS NULL`), short-circuits `null|'all'|'by_share'` targets with no resolver call, then groups remaining amenities by `target_type . '|' . json_encode(target_selector)` and calls `TargetResolver::resolveUnitIds()` once per DISTINCT pair, intersecting each result with the user's unit ids. Cost = 1 + distinct non-trivial targets.
**Alternatives considered**: (a) Chat's plain per-row `resolve()` — one query per amenity, hydrates full Unit models; (b) per-row `resolveUnitIds()` — lighter but still N queries.
**Rationale**: Chat accepts N because channels are few and rarely listed; the amenity catalog is casually browsed by every resident (`AmenityCatalogPage`, cap 200 via `AmenityController::maxLimit()`). Most clusters will have few distinct targets, so batching bounds worst-case cost without a cache layer (out of scope).

### Decision: Unknown `target_type` — safe-HIDE on read, reject on write

**Choice**: Read path checks `in_array($type, TargetResolver::TARGET_TYPES)` before resolving; unknown → not visible (mirrors Chat's `default => false`, never a 500 on list). Write path validates `Rule::in(TargetResolver::TARGET_TYPES)` in both FormRequests so bad data can't be saved (mirrors `ChatChannelCreateRequest:18`).
**Alternatives considered**: Let `TargetResolver`'s `InvalidArgumentException` bubble (500 on every resident list for one bad row); silently treat as `'all'` (leaks a mis-scoped amenity — the exact bug `TargetResolver` refuses, finding collections-12).
**Rationale**: A bad row must never expose the amenity nor break the whole catalog; validation makes the read fallback unreachable in practice.

### Decision: `unitInAmenityTarget()` lives on AmenityVisibilityService, no bypass on create

**Choice**: `ReservationService::create()` calls a new `authorizeAmenityTarget()` right after `authorizeUnitMembership()` (`ReservationService.php:105`), delegating to `AmenityVisibilityService::unitInAmenityTarget()`. It checks the reserving UNIT (not the user) against the target and applies to EVERYONE — no `update.amenity_reservation` bypass.
**Alternatives considered**: Bypass for managers (like `authorizeUnitMembership()`); inject `TargetResolver` into `ReservationService` directly.
**Rationale**: An out-of-target unit is invalid data regardless of who books — an admin booking Tower A's salon for a Tower B unit is a mistake, not a privilege. Keeping resolver usage inside one service gives one testable seam.

### Decision: NOT NULL `target_type` default `'all'` (stricter than Chat's nullable)

**Choice**: `string('target_type')->default('all')` — Postgres backfills existing rows on ADD COLUMN. Service still treats `null` defensively as `'all'`.
**Rationale**: Locked zero-behavior-change requirement; an explicit default beats Chat's nullable+treat-null-as-all because new code paths can rely on a value existing.

## Data Flow

    GET /amenity (resident) ──→ buildIndexFetchQuery ──→ scopeToAccessibleAmenities
       bypass? (null user | tokenCan('*') | tokenCan('update.amenity')) ── yes ──→ unscoped
       no ──→ whereIn('id', AmenityVisibilityService::accessibleAmenityIds(userId))
                   │ 1× UnitUser active unit ids
                   └ 1× TargetResolver::resolveUnitIds per DISTINCT (type,selector) → intersect

    GET /amenity/{id}/availability ──→ findOrFail ──→ userCanSeeAmenity? ── no ──→ 404
    POST /amenity_reservation ──→ create() ──→ authorizeUnitMembership ──→ authorizeAmenityTarget ── fail ──→ 422

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `backend/Modules/AmenityReservations/database/migrations/2026_07_11_100000_add_target_to_amenity_table.php` | Create | `Schema::table('amenity')`: `string('target_type')->default('all')->comment(...)` + `jsonb('target_selector')->nullable()->comment(...)` (mirror `chat_channel` migration:23-25); `down()` drops both |
| `backend/Modules/AmenityReservations/app/Services/AmenityVisibilityService.php` | Create | `__construct(private readonly TargetResolver $targetResolver)`; `accessibleAmenityIds(string $userId): array`, `userCanSeeAmenity(Amenity $amenity, string $userId): bool`, `unitInAmenityTarget(Amenity $amenity, string $unitId): bool` |
| `backend/Modules/AmenityReservations/app/Models/Amenity.php` | Modify | `$fillable` += `target_type`, `target_selector`; `casts()` += `'target_selector' => 'array'` |
| `backend/Modules/AmenityReservations/app/Http/Controllers/AmenityController.php` | Modify | `buildIndexFetchQuery`/`buildShowFetchQuery` overrides → private `scopeToAccessibleAmenities(Builder): Builder` (copy `ChatChannelController:176-188`, ability `update.amenity`); `availability()` adds `userCanSeeAmenity` check after `findOrFail` → `abort(404)` for non-bypass callers (Collections foreign-read precedent) |
| `backend/Modules/AmenityReservations/app/Services/ReservationService.php` | Modify | Constructor += `AmenityVisibilityService`; `create()` calls new private `authorizeAmenityTarget(Amenity $amenity, string $unitId): void` after line 105 — throws `InvalidArgumentException('Esta amenidad no está disponible para la unidad seleccionada.')` → existing 422 mapping |
| `backend/Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php` + `AmenityUpdateRequest.php` | Modify | `'target_type' => ['sometimes','nullable','string', Rule::in(TargetResolver::TARGET_TYPES)]`, `'target_selector' => ['sometimes','nullable','array']` + Spanish `target_type.in` message |
| `frontend/src/modules/amenity/schema.ts` | Modify | `IAmenity` += `target_type`/`target_selector`; form schema += `target_type` + MULTI fields (`target_unit_type_ids`, `target_tower_ids`, `target_section_ids`, `target_floors`, `target_unit_feature_ids`, `target_unit_id`, `target_unit_ids`) with per-type `superRefine` (mirror chat schema); `buildAmenityFormDefaults` seeds via SHARED `selectorIds`/`selectorString` from `@/shared/utils/targetSelector` (do NOT re-duplicate like chat did) |
| `frontend/src/modules/amenity/buildAmenityPayload.ts` | Modify | += `target_type` + `target_selector: buildTargetSelector(values.target_type, {...}, { floorIncludesTowers: true })` (shared util, chat parity) |
| `frontend/src/modules/amenity/components/AmenityForm.tsx` | Modify | New "Alcance" FieldSet with `SingleTargetSelector` + `AMENITY_TARGET_NAMES` (`as const satisfies SingleTargetNames`, mirror `ChannelFormModal:44-53`); existing `useCatalogs` call (line 89, load-on-form-open) += `UNIT_TYPE`, `TOWER`, `SECTION`, `UNIT_FEATURE` — no new eager fetch |

## Interfaces / Contracts

```php
final class AmenityVisibilityService
{
    public function __construct(private readonly TargetResolver $targetResolver) {}
    /** @return array<int, string> amenity ids visible to the user (for whereIn) */
    public function accessibleAmenityIds(string $userId): array;
    public function userCanSeeAmenity(Amenity $amenity, string $userId): bool;
    public function unitInAmenityTarget(Amenity $amenity, string $unitId): bool;
}
```

Frontend `availableTypes` for the selector: `['all','by_type','by_tower','by_section','by_feature','manual','with_vehicles']` — no `board` (chat-only), `by_floor`/`single_unit` hidden-but-alive like the shared selector's other consumers. Backend accepts the full `TargetResolver::TARGET_TYPES` (10). UI copy for `by_feature`: "unidades que tienen TODAS las características seleccionadas" — it is AND/intersection (`TargetResolver.php:213-229`), never OR.

## Testing Strategy (Standard Mode, strict_tdd false)

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature (new `backend/Modules/AmenityReservations/tests/Feature/AmenityVisibilityScopingTest.php`) | Out-of-target resident: absent from index, show 404, availability 404; in-target resident sees all four; `'all'`/legacy-null visible to everyone; `update.amenity` holder sees everything; unknown `target_type` row (inserted directly) hidden without 500 | HTTP tests mirroring `AmenityReservationReadScopingTest` |
| Feature (extend `AmenityReservationCreationTest.php`) | Create rejected 422 for out-of-target unit (resident AND admin); accepted for in-target unit | Existing factory setup + target on amenity |
| Feature (extend `AmenityCreateValidationTest.php`) | Invalid `target_type` → 422; valid type + selector persists | Request-level |
| Unit (service) | Dedupe: 3 amenities sharing one target ⇒ exactly 1 `resolveUnitIds` call; trivial targets ⇒ 0 calls | Mock/spy `TargetResolver` |
| FE unit (extend `buildAmenityPayload.test.ts` + schema test) | Selector key mapping per type; `buildAmenityFormDefaults` round-trips an existing `target_selector` | Vitest |

## Delivery Slices (400-line budget)

| Slice | Content | Est. changed lines | Flag |
|---|---|---|---|
| A1 | Migration + model + `AmenityVisibilityService` + requests validation + service unit tests + validation tests | ~280 | OK — ships inert (service unused), independently testable |
| A2 | `AmenityController` overrides + `availability()` check + `AmenityVisibilityScopingTest` | ~300 | OK — activates read scoping |
| B | `ReservationService::authorizeAmenityTarget` + creation tests | ~120 | OK |
| C | Frontend: schema + payload + form + FE tests | ~330 | OK, watch AmenityForm growth (500-line ESLint cap) |

No slice exceeds 400; chain A1 → A2 → B → C (Feature Branch Chain). Single-PR total (~1030) would blow the budget — do not collapse.

## Tenancy / Security Notes (for @tenancy-reviewer)

- **The `whereIn` scoping IS the data boundary** — a bug here leaks amenities across targets (not tenants). Audit `scopeToAccessibleAmenities` bypass conditions (`null` user / `tokenCan('*')` / `tokenCan('update.amenity')`) — exact `ChatChannelController:180` parity.
- All service queries (`Amenity`, `UnitUser`, `TargetResolver`→`Unit`) run under initialized tenancy from route middleware; `TenantScope` bounds them to the current tenant — same posture as Chat.
- `target_selector` ids are NOT validated for tenant ownership (Chat/Collections parity): a foreign tower id resolves to zero units → amenity hidden, no leak.
- `availability()` bypasses Orion's builder — the explicit in-route check is the only guard there; verify it can't be skipped.
- 404 (not 403) on show/availability for out-of-target: no existence leak (Collections `ChargeController` foreign-unit precedent).

## Migration / Rollout

Additive migration, default `'all'` backfills existing rows → zero behavior change until an admin narrows a target. Rollback: revert code; columns may stay (default reproduces current behavior) or drop via `down()`.

## Open Questions

None — performance variant, unknown-type handling, 404 semantics, and no-bypass-on-create were resolved above.
