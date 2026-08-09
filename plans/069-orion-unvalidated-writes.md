# Plan 069: Close the unvalidated Orion write surface on reservations, complaints and settings

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- \
>   Modules/AmenityReservations/app/Models/AmenityReservation.php \
>   Modules/AmenityReservations/app/Http/Requests \
>   Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php \
>   Modules/Complaint/app/Models/Complaint.php \
>   Modules/Setting/routes/api.php \
>   config/orion.php
> ```
> If any of those changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (verify with plan 068's CI runner if it has landed; not a hard block)
- **Category**: security
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

Three Orion resources accept writes to fields that no validation rule names.
The mechanism is the same in all three, and it is a property of this project's
Orion configuration rather than a one-off mistake — which is why they are fixed
together.

Concretely: a cluster board member can `PATCH /api/amenity_reservation/{id}`
with `{"reservation_status_id": "<confirmed>", "amount_outstanding": 0}` and mark
a paid amenity reservation settled — with no `Payment` row, no `Charge`, no
`PaymentApplication`, no bank-balance movement and no notification — bypassing
the entire 1170-line `ReservationService`. The same shape lets someone close a
complaint without passing its state machine, forge a case folio, or reassign who
filed it. And the generic `setting` resource lets a cluster admin rewrite any
configuration row directly, skipping the validated `config/*-settings` endpoints
that exist precisely to constrain those values.

## Current state

### The mechanism (shared by all three)

Orion is configured to fill models from the **raw request**, not from validated
data:

```php
// backend/config/orion.php:53
    'use_validated' => false,
```

```php
// backend/vendor/tailflow/laravel-orion/src/Http/Controllers/BaseController.php:669-673
    protected function retrieve(Request $request, ?string $key = null, $default = null)
    {
        if (!config('orion.use_validated')) {
            return $key ? $request->input($key, $default) : $request->all();
        }
```

So `$request->all()` reaches `performFill()`. Laravel validation does not strip
unlisted keys — it only validates the keys the rules name. Therefore **any
`$fillable` attribute that no rule mentions is writable**, and a resource with
no `*UpdateRequest` at all has *zero* update validation:

```php
// backend/core/src/Http/Request/DelegatingModuleRequest.php:85-91
    private function delegatedRules(string $suffix, string $orionRulesMethod): array
    {
        $delegate = $this->delegate($suffix);

        if ($delegate === null) {
            return [];
        }
```

`delegateClass()` resolves `Modules\<Module>\Http\Requests\<Model><Suffix>Request`
and returns `null` when that class does not exist.

Measured blast radius across the whole backend: **87 Orion resources expose
`update`; 28 of them have no `*UpdateRequest`.** Most of those 28 are harmless
label catalogs. The three in this plan are the ones whose models carry state
machine, money, or identity columns.

Note for context — this is what *correct* looks like, and it is already used in
this repo for the money tables:

```php
// backend/Modules/Collections/routes/api.php:96-100
    // (register/submit/validate/reject/cancel) and payment_application/credit_balance
    // are written exclusively by PaymentService. No store/update/destroy/restore routes.
    Orion::resource('payment', PaymentController::class)
        ->only(['index', 'search', 'show'])
        ->middleware($tenancyMiddleware);
```

### A — `amenity_reservation`

`backend/Modules/AmenityReservations/app/Http/Requests/` contains
`AmenityBlackoutCreateRequest`, `AmenityBlackoutUpdateRequest`,
`AmenityCreateRequest`, `AmenityReservationCancelRequest`,
`AmenityReservationCreateRequest`, `AmenityReservationRejectRequest`,
`AmenityReservationSurchargeRequest`, `AmenitySlotCreateRequest`,
`AmenitySlotUpdateRequest`, `AmenityUpdateRequest`.
There is **no `AmenityReservationUpdateRequest`**.

The route registers the full resource:

```php
// backend/Modules/AmenityReservations/routes/api.php:98-100
    Orion::resource('amenity_reservation', AmenityReservationController::class)
        ->withSoftDeletes()
        ->middleware($amenityTenancyMiddleware);
```

And the model's `$fillable` exposes the state machine and the money:

```php
// backend/Modules/AmenityReservations/app/Models/AmenityReservation.php:33-55
    protected $fillable = [
        'amenity_id',
        'unit_id',
        'user_id',
        'reservation_date',
        'slot_id',
        'start_at',
        'end_at',
        'reservation_status_id',
        'holds_slot',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'rules_accepted_at',
        'cost_amount',
        'deposit_amount',
        'amount_outstanding',
        'balance_due_date',
        'payment_deadline',
    ];
```

`AmenityReservationController` overrides `performStore`, `buildIndexFetchQuery`
and `buildShowFetchQuery`, and adds the custom transition endpoints (`approve`,
`reject`, `cancel`, `complete`, `noShow`, `surcharges`, `occupancy`) — but has
**no `performUpdate` guard and no `$request` property**.

**Frontend usage was checked and is not a blocker.** `AmenityReservationResource`
(`frontend/src/modules/reservation/services/AmenityReservationResource.ts:36`)
declares `basePath: '/amenity_reservation'`, and every call site across the four
reservation pages plus `ReserveAmenityWizard` uses only `create`, `show`,
`search`, `occupancy` and the custom POST transitions. **No component calls
`.update()`.**

### B — `complaint`

Here the `UpdateRequest` exists but only names five fields; everything else in
`$fillable` passes through unchecked:

```php
// backend/Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php:10-22
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'complaint_category_id' => ['sometimes', 'uuid', Rule::exists('complaint_category', 'id')->where('tenant_id', tenant('id'))],
            'complaint_priority_id' => ['sometimes', 'uuid', Rule::exists('complaint_priority', 'id')->where('tenant_id', tenant('id'))],
            'against_unit_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'against_description' => ['sometimes', 'string', 'max:2000'],
```

```php
// backend/Modules/Complaint/app/Models/Complaint.php:26-37
    protected $fillable = [
        'case_number',
        'title',
        'complaint_category_id',
        'complaint_priority_id',
        'complaint_status_id',
        'reporter_user_id',
        'assigned_user_id',
        'against_unit_id',
        'against_description',
        'resolved_at',
    ];
```

The five unguarded fields (`case_number`, `complaint_status_id`,
`reporter_user_id`, `assigned_user_id`, `resolved_at`) are each owned by a
service guard that the raw PATCH skips: `ComplaintService::isValidTransition`
(the FSM matrix), `assertManagerNotExcluded` (the confidentiality exclusion),
the assignee-must-be-a-non-excluded-manager check, and the row-locked folio
sequence. Dedicated endpoints already exist for the legitimate operations —
`complaint/{id}/transition` and `complaint/{id}/assign`
(`backend/Modules/Complaint/routes/api.php:25-26`).

### C — the generic `setting` resource

```php
// backend/Modules/Setting/routes/api.php:18
    Orion::resource('setting', SettingController::class)->withSoftDeletes()->middleware(['auth:sanctum', 'initializeTenancy']);
```

`SettingUpdateRequest` validates only the *shape* of a settings row — never
which slug is being written, nor the semantic constraints the owning module
enforces:

```php
// backend/Modules/Setting/app/Http/Requests/SettingUpdateRequest.php:13-24
    public function rules(): array
    {
        $settingId = $this->route('setting') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('setting', 'slug')->where('tenant_id', tenant('id'))->ignore($settingId)],
            'value' => ['nullable', 'string'],
            'type' => ['sometimes', 'required', 'string', 'in:boolean,string,integer,json'],
        ];
    }
```

`update.setting` is **not** in `$permissionsOnlySuperAdmin`
(`backend/database/seeders/RolePermissionTableSeeder.php:57-68`), and account
owners receive every permission not on that list:

```php
// backend/database/seeders/RolePermissionTableSeeder.php:183-185
        // Account owner: all permissions EXCEPT super-admin exclusives.
        $permissionsAccountOwner = DB::table('permissions')->select('uuid AS permission_id')
            ->whereNotIn('name', $permissionsOnlySuperAdmin)
```

So a cluster account owner can `PATCH /api/setting/{id}` and rewrite
`collection.*`, `delinquency.*`, `complaint.manager_role_keys`,
`package.*` or `amenity_reservations.enabled` — every one of which has a
validated `config/*-settings` endpoint that this route bypasses.

**Consumer check (already done, and it is why the fix is safe):**
`grep` for the generic setting resource across `frontend/src` (both `.ts` and
`.tsx`) returns **nothing**, and no backend test references `api/setting`. The
write half of this resource has no callers.

### Repo conventions that apply

- FormRequests live at `Modules/<Module>/app/Http/Requests/<Model><Action>Request.php`,
  namespaced `Modules\<Module>\Http\Requests` (note: **no `App\`** segment —
  match the existing files exactly).
- Rules use the **array** style throughout these modules; keep it.
- Validation messages shown to users are in Spanish.
- The exemplar to copy for `prohibited` — read it before writing yours:

  ```php
  // backend/Modules/Chat/app/Http/Requests/ChatChannelMemberUpdateRequest.php
  <?php

  namespace Modules\Chat\Http\Requests;

  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Validation\Rule;

  class ChatChannelMemberUpdateRequest extends FormRequest
  {
      public function rules(): array
      {
          return [
              'notif_pref' => ['sometimes', 'string', Rule::in(['all', 'mentions', 'none'])],
              'last_read_at' => ['sometimes', 'nullable', 'date'],
              // Membership identity is immutable — re-create instead of re-pointing.
              'chat_channel_id' => ['prohibited'],
              'user_id' => ['prohibited'],
          ];
      }

      public function authorize(): bool
      {
          return true;
      }
  }
  ```
- Tests: the central tree is `backend/tests/Feature/<Module>/`. AmenityReservations
  additionally has module-local tests at
  `backend/Modules/AmenityReservations/tests/Feature/`; `phpunit.xml:15-16` wires
  `Modules/*/tests/{Unit,Feature}` so both are discovered.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Targeted tests | `cd backend && php artisan test --filter=AmenityReservation` | all pass |
| Targeted tests | `cd backend && php artisan test --filter=Complaint` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Route check | `cd backend && php artisan route:list --path=setting` | see Step 3 |

## Scope

**In scope** (the only files you should modify or create):
- `backend/Modules/AmenityReservations/app/Http/Requests/AmenityReservationUpdateRequest.php` (create)
- `backend/Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php` (edit)
- `backend/Modules/Setting/routes/api.php` (edit)
- `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationOrionUpdateGuardTest.php` (create)
- `backend/tests/Feature/Complaint/ComplaintOrionUpdateGuardTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `backend/config/orion.php` — flipping `use_validated` to `true` would change
  fill behavior for all 87 resources at once. That is a large, separate,
  high-risk migration. Do not touch it.
- The other 25 resources without an `*UpdateRequest`. They are label catalogs
  whose worst case is a renamed label. Not this plan.
- `backend/Modules/AmenityReservations/routes/api.php` — do NOT change the route
  registration for `amenity_reservation`. The FormRequest is the fix here; a
  route change would also drop `destroy`/`restore`, which is a wider behavior
  change than this plan authorizes.
- `ReservationService` / `ComplaintService` — their internal logic is correct and
  is not being changed. This plan only stops callers from going around them.
- `SettingUpdateRequest` / `SettingCreateRequest` — leave them; Step 3 removes
  the routes that reach them, so editing the rules would be dead work.

## Git workflow

- Branch: `advisor/069-orion-unvalidated-writes`
- One commit per step; suggested messages:
  - `fix(amenity-reservations): prohibit FSM and money fields on the Orion update route`
  - `fix(complaint): prohibit folio, status and identity fields on the Orion update route`
  - `fix(setting): make the generic setting resource read-only`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add `AmenityReservationUpdateRequest`

Create
`backend/Modules/AmenityReservations/app/Http/Requests/AmenityReservationUpdateRequest.php`,
namespace `Modules\AmenityReservations\Http\Requests`, extending
`Illuminate\Foundation\Http\FormRequest`, modelled on the Chat exemplar above.

Mark every state-machine, money, identity and lifecycle-timestamp field
`prohibited`. That is the full list from `$fillable` minus nothing meaningful —
this resource has no legitimately PATCH-able field today, because every mutation
has its own endpoint:

`amenity_id`, `unit_id`, `user_id`, `reservation_date`, `slot_id`, `start_at`,
`end_at`, `reservation_status_id`, `holds_slot`, `approved_by`, `approved_at`,
`rejected_reason`, `cancelled_by`, `cancelled_at`, `cancellation_reason`,
`rules_accepted_at`, `cost_amount`, `deposit_amount`, `amount_outstanding`,
`balance_due_date`, `payment_deadline`.

Add a `messages()` method returning Spanish messages that point the caller at
the right endpoint, e.g. for `reservation_status_id.prohibited`:
`'El estado de la reservación se cambia con las acciones de aprobar, rechazar, cancelar, completar o marcar no-show.'`
and for the money fields:
`'Los montos de la reservación no se editan directamente; se ajustan con recargos y pagos.'`

Include a docblock stating why: the class exists so that `DelegatingModuleRequest`
finds a delegate and `updateRules()` stops returning `[]`, given
`orion.use_validated = false`.

**Verify**:
`cd backend && php -l Modules/AmenityReservations/app/Http/Requests/AmenityReservationUpdateRequest.php`
→ "No syntax errors detected"

### Step 2: Prohibit the unguarded fields in `ComplaintUpdateRequest`

Edit `backend/Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php`.
Keep the five existing rules exactly as they are. Add:

```php
            // Owned by the service layer — the Orion update route bypasses the
            // FSM matrix, the exclusion guard and the locked folio sequence.
            // Use complaint/{id}/transition and complaint/{id}/assign instead.
            'case_number' => ['prohibited'],
            'complaint_status_id' => ['prohibited'],
            'reporter_user_id' => ['prohibited'],
            'assigned_user_id' => ['prohibited'],
            'resolved_at' => ['prohibited'],
```

Add matching Spanish `messages()` entries naming the dedicated endpoints.
Do not remove or relax the existing `NOTE (review W2)` comment about evidence.

**Verify**:
`cd backend && php -l Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php`
→ "No syntax errors detected", and
`grep -c "prohibited" backend/Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php`
→ `5`

### Step 3: Make the `setting` resource read-only

First, confirm for yourself that the write half has no callers:

```bash
grep -rn "api/setting\|'/setting'\|\"/setting\"" frontend/src backend/tests backend/Modules/*/tests 2>/dev/null
```
Expected: no matches. If there ARE matches, that is a STOP condition.

Then edit `backend/Modules/Setting/routes/api.php:18` to mirror the pattern
Collections already uses for its money tables:

```php
    // Settings are written exclusively through the validated per-domain
    // endpoints (config/collection-settings, config/package-settings,
    // config/complaint-settings, config/delinquency-settings), each of which
    // constrains the values its module accepts. The generic resource would let
    // any holder of update.setting rewrite any slug directly, skipping those
    // constraints — so it is read-only. No store/update/destroy/restore routes.
    Orion::resource('setting', SettingController::class)
        ->only(['index', 'search', 'show'])
        ->middleware(['auth:sanctum', 'initializeTenancy']);
```

Note that `->only([...])` replaces `->withSoftDeletes()` — soft-delete routes
(`restore`, `batchRestore`) are write routes and are intentionally gone.

**Verify**: `cd backend && php artisan route:list --path=setting`
→ only GET routes for `setting` remain (index, search is POST by Orion
convention — that is expected and is a read operation; there must be **no**
`PATCH`, `PUT`, `DELETE`, or `POST /setting` store route).

### Step 4: Write the regression tests

Create `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationOrionUpdateGuardTest.php`.
Model its setup on an existing test in that directory — read
`backend/Modules/AmenityReservations/tests/Feature/AmenityReservationTransitionsTest.php`
first and reuse its tenant/user/amenity/reservation fixture approach and its
way of enabling the `amenity_reservations.enabled` flag.

Cover, as a user holding `update.amenity_reservation`:
1. `PATCH /api/amenity_reservation/{id}` with `{"reservation_status_id": <confirmed uuid>}`
   → **422**, and the reservation's `reservation_status_id` is unchanged in the DB.
2. `PATCH` with `{"amount_outstanding": 0}` → **422**, and `amount_outstanding`
   unchanged in the DB.
3. `PATCH` with `{"unit_id": <another unit>}` → **422**, `unit_id` unchanged.
4. A control case proving the legitimate path still works: the corresponding
   custom endpoint (e.g. `POST /api/amenity_reservation/{id}/approve`) still
   transitions the reservation.

Create `backend/tests/Feature/Complaint/ComplaintOrionUpdateGuardTest.php`,
modelled on `backend/tests/Feature/Complaint/ComplaintExclusionTest.php`.
Cover, as a user holding `update.complaint`:
1. `PUT /api/complaint/{id}` with `{"complaint_status_id": <resolved uuid>}`
   → **422**, status unchanged in the DB.
2. `PUT` with `{"case_number": "D-2026-000001"}` → **422**, folio unchanged.
3. `PUT` with `{"assigned_user_id": <some user>}` → **422**, assignee unchanged.
4. A control case: `PUT` with `{"title": "..."}` alone still returns 2xx and
   updates the title — proving the legitimate edit path is intact.

Assert on the persisted row (`assertDatabaseHas` / a fresh model read), not only
on the response code. The whole point is that the write did not land.

**Verify**:
```
cd backend && php artisan test --filter=AmenityReservationOrionUpdateGuard
cd backend && php artisan test --filter=ComplaintOrionUpdateGuard
```
→ all pass.

### Step 5: Confirm no regressions in the two modules

```
cd backend && php artisan test --filter=AmenityReservation
cd backend && php artisan test --filter=Complaint
```

Run them one at a time (never two test processes at once — see STOP conditions).

**Verify**: both suites pass with the same counts as before your change, plus
your new tests.

## Test plan

- **New tests**: the two guard test files described in Step 4 — 4 cases each
  (3 rejection cases + 1 control case proving the legitimate path still works).
- **Structural patterns to follow**:
  `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationTransitionsTest.php`
  for the amenity fixture and flag setup;
  `backend/tests/Feature/Complaint/ComplaintExclusionTest.php` for the complaint
  fixture and permission setup.
- **Why a control case in each**: a `prohibited` rule that accidentally covers a
  legitimately editable field would silently break the admin UI. The control
  case is what distinguishes "locked down" from "broken".
- **Verification**: `php artisan test --filter=AmenityReservation` and
  `--filter=Complaint`, run separately, all green.

## Done criteria

ALL must hold:

- [ ] `backend/Modules/AmenityReservations/app/Http/Requests/AmenityReservationUpdateRequest.php` exists and declares `prohibited` for all 21 fields listed in Step 1
- [ ] `grep -c "prohibited" backend/Modules/Complaint/app/Http/Requests/ComplaintUpdateRequest.php` returns `5`
- [ ] `cd backend && php artisan route:list --path=setting` shows no PATCH/PUT/DELETE route and no store route
- [ ] `cd backend && php artisan test --filter=AmenityReservationOrionUpdateGuard` passes
- [ ] `cd backend && php artisan test --filter=ComplaintOrionUpdateGuard` passes
- [ ] `cd backend && php artisan test --filter=AmenityReservation` passes
- [ ] `cd backend && php artisan test --filter=Complaint` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the 5 files in the Scope section
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Any existing test fails after Step 1 or Step 2** with a 422 on a field you
  marked `prohibited`. That means something legitimately PATCHes that field and
  the field list is wrong. Report which field and which test; do not weaken the
  test.
- **The Step 3 grep finds a consumer of the generic `setting` write routes**
  (frontend, a test, a seeder, or an artisan command). The read-only change would
  break it. Report the consumer and stop after Step 2 — Steps 1 and 2 stand on
  their own.
- **`config/orion.php` no longer has `'use_validated' => false`.** The entire
  premise of this plan is that unlisted keys reach `performFill()`. If someone
  flipped it, re-verify whether these fields are still writable before changing
  anything.
- **Tests fail with `SQLSTATE 42P01` / `42703` (`relation ... does not exist`)**
  or with catalog duplicate-key errors. Per `AGENTS.md` these are the known
  shared-test-database and non-idempotent-seeder problems, not your regression.
  Run `pgrep -f "artisan test"`, kill stragglers, re-run **one** filter at a
  time. If it persists, report and stop.
- **You find that `AmenityReservationController` gained a `performUpdate`
  override** since this plan was written. Re-read it before adding the
  FormRequest; the fix may need to change shape.

## Maintenance notes

- **The general rule this plan establishes**: while `orion.use_validated` is
  `false`, every Orion resource whose model has state-machine, money or identity
  columns in `$fillable` needs an `*UpdateRequest` that explicitly `prohibited`s
  them. A rule that does not name a field does not protect it. Worth stating in
  `.agents/skills/laravel-backend-architecture/SKILL.md` so new modules start correct — that edit
  is deliberately **not** in this plan's scope, but it is the durable fix.
- **What a reviewer should scrutinize**: the control cases in Step 4. Without
  them, this change could lock a field the UI actually edits and nothing would
  catch it.
- **Deliberately deferred**: the remaining 25 Orion resources without an
  `*UpdateRequest` are tenant-scoped label catalogs (`unit_status`,
  `announcement_priority`, `blacklist_type`, …). Their worst case is an admin
  renaming a label they can already rename through the catalog CRUD UI. Not
  worth 25 FormRequests; revisit only if one of them grows a behavior column.
- **Related but separate**: flipping `orion.use_validated` to `true` globally
  would make this whole class of bug impossible, at the cost of auditing all 87
  resources for fields that are currently filled without a rule. That is a real
  candidate for a future dedicated change, and it should be sized as L, not S.
- **`update.setting` remains granted to account owners** after this plan — the
  permission is now simply unreachable through any route. If the permission
  itself should be super-admin-only, that is a seeder change
  (`$permissionsOnlySuperAdmin` in `RolePermissionTableSeeder.php`) requiring a
  reseed, and it is not in this plan.
