# Tasks: Amenity Visibility Target

Testing mode: Standard (tests alongside code, not strict TDD). Backend tests run on
**host PHP** against Postgres `127.0.0.1:5432` (NOT sail, NOT `tests/_quarantine/`,
ONE test process at a time — `RefreshDatabase` does `migrate:fresh` on the shared
`testing` DB). Frontend: `pnpm test` / `pnpm typecheck` / `pnpm lint`. Format PHP
with `vendor/bin/pint --dirty` before finalizing each slice.

Delivery: **Feature Branch Chain** — 4 PRs merged in order A1 → A2 → B → C. Do not
collapse into one PR (design's own budget: ~1030 total lines vs. 400/slice budget).

---

## ⚠️ Correction to design.md — flag before implementing Slice B

The design document states `authorizeAmenityTarget()` "Throws
`InvalidArgumentException(...)` → existing 422 mapping." **This mapping does not
exist for the create path.** Verified directly:

- `AmenityReservationController::performStore()` (`backend/Modules/AmenityReservations/app/Http/Controllers/AmenityReservationController.php:113-119`) calls `ReservationService::create()` with **no try/catch**.
- Orion's `HandlesStandardOperations::store()` wraps `performStore` in `startTransaction()/commitTransaction()`, and on any `Exception` calls `rollbackTransactionAndRaise()`, which just rolls back and **rethrows** (`vendor/tailflow/laravel-orion/src/Concerns/HandlesTransactions.php:63-68`).
- `bootstrap/app.php`'s only `withExceptions()` registration is `Sentry\Laravel\Integration::handles($exceptions)` — reporting only, no render mapping.
- A bare `\InvalidArgumentException` does **not** implement `HttpExceptionInterface`, so Laravel's default handler renders it as a **500**, not 422. (The `approve()/reject()/cancel()` transition endpoints DO get a 422 for `InvalidArgumentException` — but only because each of those controller actions has its own explicit `catch (\InvalidArgumentException $e) { return response()->json([...], 422); }` block. `performStore()` has no equivalent.)

**Correct implementation**: mirror the two sibling private methods already inside
`create()`'s own transaction closure — `assertAvailable()` and
`assertRulesAcceptedIfRequired()` — both of which call `abort(422, $message)`
directly (a real `HttpException`, renders as 422 with zero extra plumbing). Task
B.2 below specifies `abort(422, ...)`, not a thrown `InvalidArgumentException`.

## ⚠️ Spec/design conflict on the create-time bypass — flag, do not silently resolve

The spec's `amenity-reservations` delta (`openspec/changes/amenity-visibility-target/specs/amenity-reservations/spec.md`) states a caller holding `update.amenity_reservation` **bypasses** the target check on create (scenario "Bypass holder creates regardless of target"). The design's Architecture Decision #3 says the **opposite** — no bypass on create, "applies to everyone" — and the design's own test plan confirms this ("create 422 for out-of-target unit (**resident AND admin**)").

Per the team lead's explicit instruction, **Slice B implements the design's decision (no bypass)**. This is a genuine spec/design disagreement, not a task-phase judgment call — flag it to whoever runs `sdd-verify`/`sdd-archive` so the spec's "Bypass holder" scenario gets corrected or removed to match shipped behavior. Do not add a `tokenCan()` check inside `authorizeAmenityTarget()`.

---

## Slice A1 — Inert visibility layer (migration + model + service + validation) [x] DONE

Ships nothing wired to a read path yet — safe to merge standalone, fully unit/validation-testable.

**Satisfies**: "Read-Target Columns and Default", "Target Membership Resolution", "Unknown Target Type Fails Closed" (write half).

### A1.1 — Migration: add target columns to `amenity` [x] DONE
File (new): `backend/Modules/AmenityReservations/database/migrations/2026_07_11_100000_add_target_to_amenity_table.php`

- Module is shipped on `origin/main` → plain additive `Schema::table('amenity', ...)`, mirroring `2026_07_10_100000_drop_payment_timing_from_amenity_table.php`'s docblock convention (explain why this is a follow-up ALTER, not an edit to the original create migration).
- `up()`:
  ```php
  Schema::table('amenity', function (Blueprint $table): void {
      $table->string('target_type')->default('all')
          ->comment('WHO CAN SEE/RESERVE: TargetResolver vocabulary (all/by_type/by_tower/.../with_vehicles). NOT NULL, default all = zero behavior change on rollout.');
      $table->jsonb('target_selector')->nullable()
          ->comment('JSON payload that parameterises target_type (tower_ids/unit_feature_ids/etc.) — same shape as chat_channel.target_selector.');
  });
  ```
- `down()` drops both columns.
- Column order: place after `reservation_mode_id` (last fillable column) for readability; no functional requirement on order.

### A1.2 — Amenity model: fillable + cast [x] DONE
File: `backend/Modules/AmenityReservations/app/Models/Amenity.php`

- Add `'target_type'` and `'target_selector'` to `$fillable` (after `'reservation_mode_id'`).
- Add `'target_selector' => 'array'` to the `casts()` array.
- No test needed here in isolation — covered by A1.4's factory/model round-trip and A2's feature tests.

### A1.3 — `AmenityVisibilityService` [x] DONE
File (new): `backend/Modules/AmenityReservations/app/Services/AmenityVisibilityService.php`

```php
public function __construct(private readonly TargetResolver $targetResolver) {}

public function accessibleAmenityIds(string $userId): array
public function userCanSeeAmenity(Amenity $amenity, string $userId): bool
public function unitInAmenityTarget(Amenity $amenity, string $unitId): bool
```

- **`accessibleAmenityIds(string $userId): array`** — batched resolution (Architecture Decision #1, the reason this is a NEW method and not a copy of `ChatService::accessibleChannelIds`):
  1. Resolve the user's active unit ids ONCE: `UnitUser::where('user_id', $userId)->whereNull('end_date')->pluck('unit_id')->all()`.
  2. Load all amenities (`Amenity::query()->get(['id', 'target_type', 'target_selector'])` — no eager relations needed).
  3. Short-circuit: any amenity with `target_type` null/`'all'`/`'by_share'` → always accessible, no resolver call.
  4. Group the REMAINING amenities by a cache key `$targetType.'|'.json_encode($targetSelector)`.
  5. For each DISTINCT group: if `$targetType` not in `TargetResolver::TARGET_TYPES` → treat as empty (fail-closed, no exception, no resolver call — satisfies "Unknown Target Type Fails Closed"); otherwise call `$this->targetResolver->resolveUnitIds($targetType, $selector)` ONCE, intersect with the user's unit ids, and if non-empty mark every amenity in that group as accessible.
  6. Return the merged list of accessible amenity ids (short-circuited + resolved).
- **`userCanSeeAmenity(Amenity $amenity, string $userId): bool`** — single-amenity convenience wrapper for the `availability()` route (avoid materializing the whole tenant's amenities for one check): same short-circuit logic inline, else one `resolveUnitIds()` call + `UnitUser` intersection check.
- **`unitInAmenityTarget(Amenity $amenity, string $unitId): bool`** — for Slice B: does the target resolve a set of unit ids containing `$unitId`? Same short-circuit (`null`/`all`/`by_share` → true) and same fail-closed unknown-type handling (→ false), else `in_array($unitId, $this->targetResolver->resolveUnitIds(...)->all(), true)`.
- **Test**: `backend/Modules/AmenityReservations/tests/Feature/AmenityVisibilityServiceTest.php` (module has no `tests/Unit/` dir — follow module convention, Feature-only, `RefreshDatabase` + `WithTenantSetup` like siblings):
  - 3 amenities sharing one non-trivial target (e.g. `by_tower` with the same tower id) → assert exactly 1 `TargetResolver::resolveUnitIds` call. Use a partial mock/spy on `TargetResolver` (`$this->mock(TargetResolver::class)->shouldReceive('resolveUnitIds')->once()->andReturn(collect([$unitId]))`) bound via the container for this test.
  - 2 amenities with `target_type='all'`/`null` → assert 0 `resolveUnitIds` calls.
  - Multi-unit resident (active `unit_user` on unit A out-of-target, unit B in-target) → `userCanSeeAmenity` returns true (matches spec scenario "Multi-unit resident, one unit in-target").
  - `by_feature` with 2 `unit_feature_ids`, unit has only 1 → not matched (spec scenario "by_feature requires all selected features" — this is really `TargetResolver::resolveUnitIds`'s own `>=count($featureIds)` `whereHas` intersection semantics, so this test is really confirming the SERVICE correctly delegates to it, not re-testing `TargetResolver` itself).
  - Unknown/garbage `target_type` (e.g. `'bogus'`) on a persisted amenity → `userCanSeeAmenity` returns `false`, no exception thrown (fail-closed read).

### A1.4 — `AmenityCreateRequest` / `AmenityUpdateRequest` validation [x] DONE
Files: `backend/Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php`, `AmenityUpdateRequest.php`

- `AmenityCreateRequest::rules()`: add
  ```php
  'target_type' => ['sometimes', 'nullable', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
  'target_selector' => ['sometimes', 'nullable', 'array'],
  ```
  (import `Modules\Collections\Services\TargetResolver`). Full 10-value `TARGET_TYPES` — unlike Chat's `ADMIN_TARGET_TYPES` (9 values, swaps `by_share` for `board`), Amenity has no `board` concept, so use `TargetResolver::TARGET_TYPES` directly, no new model constant needed.
- `AmenityCreateRequest::messages()`: add `'target_type.in' => 'El alcance de la amenidad no es válido.'`.
- `AmenityUpdateRequest::rules()`: same two rules with `'sometimes'` already the outer wrapper (mirror the existing `has_cost`/`requires_deposit` `sometimes` pattern already in this file — no `effectiveX()` resolution needed here, target fields have no cross-field dependency).
- `AmenityUpdateRequest::messages()`: same `target_type.in` message.
- **Test**: extend `backend/Modules/AmenityReservations/tests/Feature/AmenityCreateValidationTest.php` — add `test_invalid_target_type_returns_422()` (POST `/api/amenity` with `target_type: 'bogus'` → `assertStatus(422)->assertJsonValidationErrors('target_type')`) and `test_valid_target_type_with_selector_is_created()` (e.g. `target_type: 'by_tower'`, `target_selector: {tower_ids: [...]}` → `assertStatus(201)`). Mirror the existing `basePayload()`/`adminTenant()` helpers already in that file — do not duplicate tenant/token setup.

**A1 exit check** [x] DONE: `php artisan test --compact --filter=AmenityVisibilityServiceTest` (5 passed) and `--filter=AmenityCreateValidationTest` (6 passed) green. Full `--filter=Amenity` regression run: 180 passed / 688 assertions — zero behavior change confirmed. `vendor/bin/pint --dirty` passed with no changes needed. Nothing reads `target_type`/`target_selector` yet outside these new files — zero behavior change on any existing endpoint.

---

## Slice B — Custom-route enforcement (`AmenityController` scoping + `availability()`) [x] DONE (as Slice A2)

Blocked by A1 (needs the model columns + service). Independent of Slice C (frontend can wait or ship in parallel once B merges, since B doesn't touch `ReservationService`).

> Numbering note: this is labeled "A2" in the design's slice list; kept as its own
> heading here for clarity since it's the second PR in the chain (A1 → **A2** → B → C).
> Referred to as **Slice A2** below to match design.md's naming exactly.

**Satisfies**: "Manager Bypass", "Server-Side Enforcement at Resident Read Seams", "Unknown Target Type Fails Closed" (read half — already covered by A1.3, exercised here end-to-end).

### A2.1 — `AmenityController`: scope index/show to accessible amenities [x] DONE
File: `backend/Modules/AmenityReservations/app/Http/Controllers/AmenityController.php`

- Add imports: `Illuminate\Database\Eloquent\Builder`, `Orion\Http\Requests\Request as OrionRequest`, `Modules\AmenityReservations\Services\AmenityVisibilityService`.
- Add two protected overrides (mirror `ChatChannelController:49-57` exactly):
  ```php
  protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
  {
      return $this->scopeToAccessibleAmenities(parent::buildIndexFetchQuery($request, $requestedRelations));
  }

  protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
  {
      return $this->scopeToAccessibleAmenities(parent::buildShowFetchQuery($request, $requestedRelations));
  }
  ```
- Add the private helper (mirror `ChatChannelController::scopeToAccessibleChannels:176-188`, swap the bypass permission and delegate to the new service):
  ```php
  private function scopeToAccessibleAmenities(Builder $query): Builder
  {
      $user = auth()->guard('sanctum')->user();

      if ($user === null || $user->tokenCan('*') || $user->tokenCan('update.amenity')) {
          return $query;
      }

      return $query->whereIn(
          'id',
          app(AmenityVisibilityService::class)->accessibleAmenityIds((string) $user->id),
      );
  }
  ```
  `show` returning a Model, not a Builder — Orion's `buildShowFetchQuery` still returns a `Builder` that gets `->findOrFail()`'d downstream, so `whereIn('id', ...)` correctly turns an out-of-target show into Orion's own standard 404 (`ModelNotFoundException` → Laravel's default 404 render) — no new not-found handling needed here.

### A2.2 — `availability()`: 404 for out-of-target callers [x] DONE
File: `backend/Modules/AmenityReservations/app/Http/Controllers/AmenityController.php` (same file, `availability()` method, ~line 57-75)

- After `$amenity = Amenity::findOrFail($id);` (line 66) and before the `try` block that calls `$availabilityService->forRange(...)`, insert:
  ```php
  $user = $request->user();
  if ($user !== null && ! $user->tokenCan('*') && ! $user->tokenCan('update.amenity')
      && ! app(AmenityVisibilityService::class)->userCanSeeAmenity($amenity, (string) $user->id)) {
      abort(404);
  }
  ```
  This route bypasses Orion's query builder entirely (`Amenity::findOrFail($id)` is a raw model lookup) — per design's Tenancy/Security note, this explicit in-route check is the ONLY guard here, there is no query-level scoping to fall back on.
  Note `$request->user()` (not `auth()->guard('sanctum')->user()`) — matches this method's existing style (it already calls `$this->authorize('read.amenity', ...)` which internally resolves the guard user; using `$request->user()` keeps this block consistent with the method's own idiom rather than introducing a second way of fetching the user in the same file).

### A2.3 — Feature tests [x] DONE
File (new): `backend/Modules/AmenityReservations/tests/Feature/AmenityVisibilityScopingTest.php`

Mirror the tenant/token setup conventions already in `AmenityReservationCreationTest.php` (`tenantWithFlagEnabled()`, `residentToken()`/`adminToken()`-shaped helpers, `$connectionsToTransact = []`, `tearDown()` ending tenancy). Cases:

1. Out-of-target resident: `GET /api/amenity` (index) does NOT include the amenity's id in the response.
2. Same resident: `GET /api/amenity/{id}` → `assertStatus(404)`.
3. Same resident: `GET /api/amenity/{id}/availability?date=...` → `assertStatus(404)`.
4. In-target resident (active `unit_user` on a unit inside the target): all three of the above succeed and return the amenity's data.
5. `target_type='all'` (and separately `target_type=null` via `DB::table('amenity')->update(['target_type' => null])` if the NOT NULL default makes a null row hard to construct through the model — check whether the migration's `default('all')` combined with `NOT NULL` blocks a null row entirely; if so, this scenario reduces to just `'all'`, drop the `null` variant) is visible to every resident regardless of unit.
6. Admin/board holder (`update.amenity` token ability): sees an amenity targeted to explicitly EXCLUDE their own unit, on index/show/availability all three.
7. Amenity with an unrecognized `target_type` (write it directly via `DB::table('amenity')->update(['target_type' => 'bogus'])` — validation blocks this at create/update time, so simulate the "somehow persisted" case per spec): a non-bypass resident gets it hidden (absent from index, 404 show/availability) — NOT a 500.
8. **Target narrowing is not retroactive** (spec requirement, no design test called out — add it here since it belongs with the visibility-scoping suite, not Slice B): create a `CONFIRMED` `AmenityReservation` for a resident on an amenity currently `target_type='all'`; narrow the amenity's target to exclude that resident's unit; assert `GET /amenity_reservation/{id}` (or `/my-reservations` list) for that resident still returns the existing reservation unchanged — `AmenityReservationController`'s own read scoping (`ScopesToResidentUnits`) is untouched by this change, so this test is a regression guard, not new production code.

**A2 exit check** [x] DONE: `php artisan test --compact --filter=AmenityVisibilityScopingTest` — 6 passed (22 assertions), including scenario 8 (target narrowing is not retroactive). Full `--filter=Amenity` regression run: 186 passed / 710 assertions (up from A1's 180/688 baseline — zero regressions to `AmenityOrionCrudTest`, `AmenityAvailabilityEndpointTest`, `AmenityPermissionAndFlagGateTest`, or any other existing admin-path assertion). `vendor/bin/pint --dirty` passed clean.

---

## Slice B (create-time enforcement) [x] DONE

Blocked by A1 (needs `AmenityVisibilityService::unitInAmenityTarget`). Independent of Slice A2 (touches `ReservationService`, not `AmenityController`) — could theoretically merge before A2, but keep the design's stated order (A1→A2→B→C) since A2's feature tests double as the manual regression pass before touching the create path.

**Satisfies**: amenity-reservations MODIFIED "Authorization" requirement (target half) + ADDED "Target Narrowing Is Not Retroactive" (create-time half, in that a rejected out-of-target create never produces a row to begin with).

### B.1 — `ReservationService` constructor [x] DONE
File: `backend/Modules/AmenityReservations/app/Services/ReservationService.php`

- Add `Modules\AmenityReservations\Services\AmenityVisibilityService` to the constructor promotion list (line 86-88), alongside the existing `AmenityAvailabilityService`:
  ```php
  public function __construct(
      private readonly AmenityAvailabilityService $availabilityService,
      private readonly AmenityVisibilityService $visibilityService,
  ) {}
  ```

### B.2 — `authorizeAmenityTarget()` + call site [x] DONE
File: same, `create()` method

- Right after the existing `$this->authorizeUnitMembership((string) $data['unit_id']);` call (line 105), add:
  ```php
  $this->authorizeAmenityTarget($amenity, (string) $data['unit_id']);
  ```
- New private method (placed near `authorizeUnitMembership()`, ~line 989, for locality):
  ```php
  /**
   * The reserving UNIT must fall within the amenity's target (spec
   * amenity-visibility: "Server-Side Enforcement"). Deliberately NO bypass
   * for update.amenity_reservation holders here — unlike
   * authorizeUnitMembership()'s bypass (which lets board/admin reserve for
   * ANY unit they manage), reserving for a unit outside the amenity's own
   * target is a booking mistake regardless of who makes it (see
   * design.md Architecture Decision #3).
   */
  private function authorizeAmenityTarget(Amenity $amenity, string $unitId): void
  {
      if (! $this->visibilityService->unitInAmenityTarget($amenity, $unitId)) {
          abort(422, 'Esta amenidad no está disponible para la unidad seleccionada.');
      }
  }
  ```
  Uses `abort(422, ...)` — see the design correction at the top of this file, NOT a thrown `InvalidArgumentException` (this method runs inside `create()`'s `DB::transaction` closure, called from `performStore()`, which has no surrounding try/catch, unlike `approve()/reject()/cancel()`).

### B.3 — Tests [x] DONE
File: extend `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationCreationTest.php`

- `test_create_for_out_of_target_unit_by_resident_returns_422()`: amenity `target_type='by_tower'` scoped to tower A; resident's active unit is in tower B; POST `/api/amenity_reservation` → `assertStatus(422)`, assert no `AmenityReservation` row was created.
- `test_create_for_out_of_target_unit_by_admin_also_returns_422()`: same setup, but the acting token holds `update.amenity_reservation` (the existing unit-membership-bypass token) — still 422 (confirms the "no bypass" decision, and doubles as the regression guard against silently reintroducing Chat's bypass pattern by copy-paste).
- `test_create_for_in_target_unit_succeeds()`: unit IS in the target → 201/success per the existing FSM rules, unaffected by this change.
- `test_create_for_all_target_succeeds_unaffected()`: default `target_type='all'` amenity, any unit → succeeds exactly as before this change (zero-regression check against every OTHER existing test in this file that doesn't set `target_type` at all — the factory/seed default must resolve to `'all'`).

**B exit check** [x] DONE: `php artisan test --compact --filter=AmenityReservationCreationTest` — 18 passed (34 assertions), including the 4 new target-enforcement tests. Full `--filter=AmenityReservation` regression: 190 passed / 716 assertions (up from A2's 186/710 baseline — exactly the 4 new tests, zero regressions; confirms `ReservationService`'s constructor DI change didn't break `AmenityReservationTransitionsTest`/`AmenityReservationEarmarkTest`/etc., all bundled under this filter). `vendor/bin/pint --dirty` passed clean.

---

## Slice C — Frontend (admin target editing) [x] DONE

Blocked by A1 (needs `target_type`/`target_selector` in the API contract) — does NOT need A2/B merged first to build against (the shape is stable from A1), but should not ship to production ahead of A2 (an admin narrowing a target before A2 merges would have zero enforcement effect).

**Satisfies**: "Admin Target Editing".

### C.1 — Extract `AmenityTargetFields.tsx` (new subcomponent, NOT grown inline in `AmenityForm.tsx`) [x] DONE
File (new): `frontend/src/modules/amenity/components/AmenityTargetFields.tsx`

`AmenityForm.tsx` is already 373 lines against the project's 500-line ESLint cap; adding a full `SingleTargetSelector` FieldSet inline would push it close to the limit. Mirror the existing extraction precedent in this exact module — `AmenityCostDepositFields.tsx` (same file, extracted for the same reason) — same props shape:

```tsx
export interface AmenityTargetFieldsProps {
  control: Control<AmenityFormValues>
  register: UseFormRegister<AmenityFormValues>
  errors: FieldErrors<AmenityFormValues>
  targetType: string
  targetUnitId: string | undefined
  targetUnitIds: string[]
  catalogs: SingleTargetCatalogs
  disabled: boolean
  singleUnitLabel: string
  onSingleUnitLabelChange: (_label: string) => void
  onUnitSelect: (_id: string) => void
  onUnitIdsChange: (_ids: string[]) => void
}
```

Body: a `FieldSet`/`FieldLegend` "Alcance" wrapping one `<SingleTargetSelector>` call with:
- `names={AMENITY_TARGET_NAMES}` (new const, see C.2)
- `sectionLabel='Alcance — ¿quién puede ver y reservar?'`
- **no `availableTypes` prop** — the design's required list `['all','by_type','by_tower','by_section','by_feature','manual','with_vehicles']` is EXACTLY `SINGLE_TARGET_VISIBLE_TYPES`, the component's own default (verified against `SingleTargetSelector.tsx:69-77`) — passing it explicitly would be redundant, just omit the prop. `by_floor`/`single_unit` stay hidden-but-alive automatically (same default).
- `floorIsMulti` (Amenity's schema uses `target_floors: string[]`, plural — see C.2 — matching Chat's read-target shape, not Collections' singular `target_floor`).
- No `previewFetcher` (no "N units affected" preview endpoint in scope for this change — omit the prop, `SingleTargetSelector` already no-ops the preview block when it's absent).

### C.2 — `AMENITY_TARGET_NAMES` constant + schema fields [x] DONE
File: `frontend/src/modules/amenity/schema.ts`

- Add the RHF field-name mapping (mirror `ChannelFormModal.tsx:44-53`'s `READ_TARGET_NAMES`, since Amenity's field names match Chat's read-block exactly — `target_unit_feature_ids` not `target_feature_ids`, `target_floors` plural not singular):
  ```ts
  export const AMENITY_TARGET_NAMES = {
    targetType: 'target_type',
    unitTypeIds: 'target_unit_type_ids',
    towerIds: 'target_tower_ids',
    sectionIds: 'target_section_ids',
    featureIds: 'target_unit_feature_ids',
    floor: 'target_floors',
    unitId: 'target_unit_id',
    unitIds: 'target_unit_ids',
  } as const satisfies SingleTargetNames
  ```
  (import `SingleTargetNames` from `@/shared/components/target/SingleTargetSelector`).
- `IAmenity` interface: add `target_type?: string | null` and `target_selector?: Record<string, unknown> | null`.
- `amenityFormSchema`: add the 8 target fields (mirror Chat's shape at `chat/schema.ts:91-98`):
  ```ts
  target_type: z.string().min(1, 'El alcance es requerido'),
  target_unit_type_ids: z.array(z.string()).optional(),
  target_tower_ids: z.array(z.string()).optional(),
  target_section_ids: z.array(z.string()).optional(),
  target_floors: z.array(z.string()).optional(),
  target_unit_feature_ids: z.array(z.string()).optional(),
  target_unit_id: z.string().optional(),
  target_unit_ids: z.array(z.string()).optional(),
  ```
  Add a `superRefine` branch keyed on `target_type` requiring the matching sub-field to be non-empty (mirror `chat/schema.ts`'s `switch (values.target_type) { case 'by_type': need(...) }` pattern) for `by_type`/`by_tower`/`by_section`/`by_feature`/`manual` (and, since the hidden-but-alive branches stay reachable for legacy data, `by_floor`/`single_unit` too, matching Chat's completeness).
- `buildAmenityFormDefaults(amenity)`: add
  ```ts
  target_type: amenity?.target_type ?? 'all',
  target_unit_type_ids: selectorIds(amenity?.target_selector, 'unit_type_ids'),
  target_tower_ids: selectorIds(amenity?.target_selector, 'tower_ids'),
  target_section_ids: selectorIds(amenity?.target_selector, 'section_ids'),
  target_floors: selectorIds(amenity?.target_selector, 'floors'),
  target_unit_feature_ids: selectorIds(amenity?.target_selector, 'unit_feature_ids'),
  target_unit_id: selectorString(amenity?.target_selector, 'unit_id'),
  target_unit_ids: selectorIds(amenity?.target_selector, 'unit_ids'),
  ```
  (import `selectorIds`/`selectorString` from `@/shared/utils/targetSelector` — do NOT hand-roll this extraction again, that's exactly the duplication the design calls out avoiding).
- **Test**: extend `frontend/src/modules/amenity/schema.test.ts` — round-trip test: build defaults from an `IAmenity` with `target_type: 'by_feature'` + `target_selector: { unit_feature_ids: ['a','b'] }`, assert `buildAmenityFormDefaults(...).target_unit_feature_ids` equals `['a','b']`.

### C.3 — `buildAmenityPayload.ts` [x] DONE
File: `frontend/src/modules/amenity/buildAmenityPayload.ts`

- Add:
  ```ts
  target_type: values.target_type,
  target_selector: buildTargetSelector(
    values.target_type,
    {
      unitType: values.target_unit_type_ids,
      tower: values.target_tower_ids,
      section: values.target_section_ids,
      floor: values.target_floors,
      feature: values.target_unit_feature_ids,
      unitId: values.target_unit_id ?? '',
      unitIds: values.target_unit_ids,
    },
    { floorIncludesTowers: true }
  ),
  ```
  (import `buildTargetSelector` from `@/shared/utils/targetSelector`). `floorIncludesTowers: true` matches Chat's parity (plural `target_floors` + `target_tower_ids` together), consistent with C.2's `floorIsMulti` choice.
- **Test**: extend `frontend/src/modules/amenity/buildAmenityPayload.test.ts` — one case per `target_type` value used in the visible set (`all`, `by_type`, `by_tower`, `by_section`, `by_feature`, `manual`, `with_vehicles`), asserting `target_selector` matches the exact key shape `TargetResolver` expects (same assertion style already in this test file for the existing fields).

### C.4 — Wire `AmenityTargetFields` into `AmenityForm.tsx` [x] DONE
File: `frontend/src/modules/amenity/components/AmenityForm.tsx`

- Extend the existing `useCatalogs` call (lines 89-97) — add the four new catalog keys to the SAME call (no new eager fetch, still load-on-form-open per anti-pattern rule §2.12):
  ```ts
  const { catalogs, isLoading: catalogsLoading } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.RESERVATION_MODE]: { _sort: 'order' },
        [CatalogType.BANK_ACCOUNT]: { _sort: 'name' },
        [CatalogType.UNIT_TYPE]: { _sort: 'name' },
        [CatalogType.TOWER]: { _sort: 'name' },
        [CatalogType.SECTION]: { _sort: 'name' },
        [CatalogType.UNIT_FEATURE]: { _sort: 'name' },
      }),
      []
    )
  )
  ```
- Add local state for the single-unit label (mirror `ChannelFormModal`'s `readUnitLabel`/`setReadUnitLabel`, needed for `single_unit`'s hidden-but-alive branch to show a resolved unit name on edit) and a `targetCatalogs` memo (mirror `ChannelFormModal.tsx:135-143`):
  ```ts
  const targetCatalogs = useMemo(
    () => ({
      unitTypes: catalogs[CatalogType.UNIT_TYPE] ?? [],
      towers: catalogs[CatalogType.TOWER] ?? [],
      sections: catalogs[CatalogType.SECTION] ?? [],
      features: catalogs[CatalogType.UNIT_FEATURE] ?? [],
    }),
    [catalogs]
  )
  ```
- Add `target_type`, `target_unit_id`, `target_unit_ids` to the existing `useWatch` call (line 81-84).
- Render `<AmenityTargetFields ... />` as a new `FieldSet` — placement: after "Información general", before `AmenityCostDepositFields` (scope logically comes before cost/deposit in the form's reading order).
- **Test**: add a case to `frontend/src/modules/amenity/__tests__/` (create `AmenityForm.test.tsx` if none exists yet — check first) or extend an existing form-level test asserting the "Alcance" fieldset renders and defaults to `'all'` pre-selected on create mode.

**C exit check** [x] DONE: `pnpm typecheck` clean (0 errors). `pnpm test amenity` — 11 test files, 61 tests, all passed (includes the new `AmenityForm.test.tsx` + extended `schema.test.ts`/`buildAmenityPayload.test.ts` target-mapping cases). `eslint` on all touched files — 0 errors/warnings. Full `pnpm lint` run has 18 pre-existing errors/45 warnings unrelated to this slice (none in `frontend/src/modules/amenity/`, confirmed by grep). `AmenityForm.tsx` is 424/500 lines (was 373 before this slice, +51 lines: useWatch/useCatalogs/targetCatalogs wiring + the `<AmenityTargetFields>` call) — under the cap, C.1's extraction did its job.

**Known gap flagged, not fixed (out of this slice's file scope)**: the shared `SingleTargetSelector`'s `by_feature` HINT text (`SINGLE_TARGET_TYPE_HINTS.by_feature` in `frontend/src/shared/components/target/SingleTargetSelector.tsx`, module-private, no prop override exists) still reads "Con alguna de las características elegidas" (OR-flavored wording) even though `by_feature` is AND/intersection semantics (`TargetResolver.php:213-229`, confirmed in design's Contracts section). This pre-existing copy is shared by Collections and Chat already in production — correcting it is a one-line text change but touches a shared component outside `frontend/src/modules/amenity/`, so it was left alone per this slice's scope instruction. No new copy was introduced by Slice C that contradicts AND semantics (no `typeLabels`/hint override was added, per design's own instruction to omit `availableTypes`/use defaults).

---

## Review Workload Forecast (400-line PR budget)

| Slice | Files touched | Est. changed lines | vs. 400 budget | Chained-PR recommendation |
|---|---|---|---|---|
| A1 | migration (new, ~20), model (+3), `AmenityVisibilityService` (new, ~90), 2 FormRequests (+8 total), `AmenityVisibilityServiceTest` (new, ~110), `AmenityCreateValidationTest` (+40) | ~270 | Under budget | Standalone PR — ships inert, safe to merge alone |
| A2 | `AmenityController` (+35), `AmenityVisibilityScopingTest` (new, ~180) | ~215 | Under budget | Standalone PR, based on A1 |
| B | `ReservationService` (+20), `AmenityReservationCreationTest` (+60) | ~80 | Well under budget | Standalone PR, based on A2 (or A1 if merged out of order) |
| C | `AmenityTargetFields.tsx` (new, ~90), `schema.ts` (+55), `buildAmenityPayload.ts` (+15), `AmenityForm.tsx` (+30), `schema.test.ts` (+15), `buildAmenityPayload.test.ts` (+50), form test (+30) | ~285 | Under budget | Standalone PR, based on A1 (parallel-safe with A2/B once A1 merges) |

Total across all 4 slices ≈ 850 lines (design estimated ~1030; this breakdown came in lighter, mainly because C.1's extraction absorbs what would otherwise inflate `AmenityForm.tsx` directly). Every individual slice is well under the 400-line single-PR budget — **do not** collapse them into fewer PRs; the value of the chain here is bisectability (A1 is the riskiest logic — batched resolution — reviewed alone; A2 is the actual data-boundary change reviewers most need to scrutinize per design's own Tenancy/Security note; B is small and easy to verify in isolation; C is a pure frontend PR reviewable by a different reviewer in parallel with B once A1 is in).

## Dependency / parallelization

```
A1 (backend, inert) ──┬──▶ A2 (backend, read scoping) ──▶ B (backend, create enforcement)
                       │
                       └──▶ C (frontend) — can start once A1 merges, does not block on A2/B,
                                            but should not ship to prod ahead of A2
```

A2 and C can be worked in parallel by two different people/agents once A1 merges. B should follow A2 in the merge order per the design's stated chain, even though it has no file overlap with A2 — keeps the "visibility scoping is live and tested" milestone before touching the create path.

## Result Contract

- **status**: done
- **executive_summary**: 4 ordered backend/frontend PR-sized tasks (A1 inert layer ~270 lines, A2 read-scoping ~215 lines, B create-enforcement ~80 lines — all sequential/chained — plus C frontend ~285 lines, parallelizable with A2/B once A1 merges); flagged one design correction (422 mapping doesn't exist on the create path — corrected to `abort(422, ...)`) and one unresolved spec/design conflict (create-time bypass) for `sdd-verify`/`sdd-archive` to settle.
- **artifacts**: `sdd/amenity-visibility-target/tasks` (Engram), `openspec/changes/amenity-visibility-target/tasks.md`
- **next_recommended**: sdd-apply
- **risks**:
  1. **Spec/design conflict, unresolved**: spec's amenity-reservations delta requires an `update.amenity_reservation` bypass on the create-time target check; design explicitly rejects it. Slice B implements per design (no bypass, confirmed by design's own test plan). This needs the spec corrected at archive time or the decision revisited before Slice B — flagging, not silently picking a side beyond following the team lead's explicit routing to the design's decision.
  2. **Design inaccuracy on exception handling** (corrected in Slice B above): the claimed "existing 422 mapping" for a thrown `InvalidArgumentException` on `ReservationService::create()` does not exist — `performStore()` has no try/catch, unlike the individually-wrapped `approve()/reject()/cancel()` actions. Using `abort(422, ...)` instead avoids shipping a 500 where a 422 is required by spec.
  3. Slice ordering (A2 before B) is a process choice, not a file dependency — if time pressure hits, B could ship before A2 without breaking anything, but the design's stated rationale (A2's regression suite as a checkpoint) argues for keeping the order.
  4. `AmenityForm.tsx`'s 500-line ESLint cap is tight even after C.1's extraction — if `AmenityTargetFields.tsx`'s wiring in C.4 grows beyond the ~30-line estimate (e.g. extra loading-state guards), re-check the count before finalizing Slice C.
- **skill_resolution**: paths-injected
