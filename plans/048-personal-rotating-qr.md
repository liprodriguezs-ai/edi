# Plan 048: Personal rotating "Mi QR" for residents (60s server-clock token) + guard-scan resolution

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Access Modules/UserProfile app/Models/User.php` and
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access src/App.tsx src/shared/hooks/useNavSections.ts`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: L
- **Depends on**: none (additive; does not depend on plan 040 — that relation-key fix is already applied)
- **Category**: feature
- **Planned at**: backend `fb2062b`, frontend `0887d5a`, 2026-06-22

## Why this matters

Residents and renters physically live in the cluster and must enter every day,
yet the entire Access module today only models THIRD-PARTY visit passes (the
`visitor` table: name, plate, motive, `created_by_user_id`). There is no way for
a resident to identify themselves at the gate without a guard doing a manual
entry. Spec §14.2 lists only "Generar QR para mi visita" and "Ver mis QR
generados" — both about visitors, never the resident's own entry.

This plan adds a **personal rotating QR** ("Mi QR"): each resident has a single
short-lived token that auto-rotates every 60 seconds. The guard scans it with
the SAME caseta scanner already used for visit QRs, and a resident self-entry is
stamped in the immutable `access_log`. The rotation makes a screenshotted code
useless after a minute (it is a live credential, not a static pass). The
countdown is driven by the SERVER clock (the endpoint returns
`seconds_remaining`), so a tampered client clock cannot extend validity, and
regeneration is **lazy/on-read** — there is NO cron.

The data foundation already fits: `user_profile` is tenant-scoped and unique per
`(tenant_id, user_id)`, `access_log.visitor_id` and `entered_at` are already
nullable (so a self-entry needs no visitor row), and both QR libraries are
installed (`qrcode.react` for rendering, `html5-qrcode` for the guard scanner).

## Current state

### `user_profile` is already exactly one-row-per-(user, tenant)

`backend/Modules/UserProfile/database/migrations/2026_05_31_063010_create_user_profile_table.php`:
```php
Schema::create('user_profile', function (Blueprint $table) {
    BaseModel::columns($table, true); // id, timestamps, soft-delete, audit, tenant_id
    $table->uuid('user_id')->index()->comment('References user.id (no FK ... single-DB mode)');
    // ... PII columns ...
    $table->unique(['tenant_id', 'user_id']);   // line 30 — perfect per-(user,tenant) key
});
```
`backend/Modules/UserProfile/app/Models/UserProfile.php`:
```php
use BelongsToTenant;                               // line 18 — tenant-scoped
protected $fillable = ['user_id','rfc','curp',...];// lines 23-32
protected $auditExclude = ['rfc','curp',...];      // lines 41-48 — exclude credentials from audit
```
`backend/app/Models/User.php:240-247` — `currentProfile()` returns the row for the initialized tenant (null if tenancy not initialized). Use it.

### `access_log` already supports a no-visitor row

`backend/Modules/Access/database/migrations/2026_05_31_200554_create_access_log_table.php:18`
`$table->foreignUuid('visitor_id')->nullable()...nullOnDelete()` and
`2026_06_09_100000_add_capture_and_denial_columns_to_access_log_table.php:26`
`$table->timestamp('entered_at')->nullable()->change();`
`AccessLog` $fillable (AccessLog.php:26-41) includes `visitor_id, unit_id, visitor_name, access_entry_type_id, access_verification_id, guard_user_id, entered_at` — a self-entry row needs no new column.

### Token shape and scan lookup (the collision constraint)

`backend/Modules/Access/app/Services/AccessService.php:555-568` — visit tokens are
`strtoupper(Str::random(4).'-'.Str::random(4))` → `ABCD-1234`, unique per tenant on `visitor.qr_token`.
`AccessService.php:72-79` — `scanQr` looks up `Visitor::where('qr_token', $token)->lockForUpdate()->first()` ONLY; a personal token MUST be distinguishable before this lookup. The `resolveExpiry`/state-machine logic lives in `scanQr` lines 81-149.

### Self-scoped custom-route pattern (copy this for the new endpoint)

`backend/Modules/Access/routes/api.php:18` defines
`$customMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];`
and lines 72-83 register self-scoped GET/POST actions in that group.
`AccessLogController::myLog()` (AccessLogController.php:139-151) is the canonical
self-scoped read: NO `$this->authorize(...)`, resolves the caller via
`$request->user()`, returns the caller's own data. `myQr()` mirrors this.

### Frontend resident self-pages and the scanner

`frontend/src/modules/access/services/AccessResource.ts:18-72` — `AccessLogResource`
is a `createCrudApi` with `customMethods` (`now`, `myLog`, `scanQr`, ...). Add `myQr` here.
`frontend/src/modules/access/schema.ts` — interfaces live here (e.g. `IVisitor`, `IAccessLog`, `VISITOR_STATUS` UUID map). Add the `IMyQr` interface here.
`frontend/src/modules/access/components/VisitorQrModal.tsx` — the existing large-QR
rendering with `QRCodeSVG` (qrcode.react). Reuse its style for `MyQrPage`.
`frontend/src/App.tsx` — `/my-access-log` and `/my-unit` live under `PermissionRoute permissionEntity="unit"`; add `/my-qr` near them.
`frontend/src/shared/hooks/useNavSections.ts` — `fixedNavItems` renders role-gated
self-page links (e.g. "Mi expediente"). Add a "Mi QR" item gated to `[RESIDENT, RENTER]`.
`frontend/src/shared/interfaces/Entity.ts` — `RESIDENT='resident'`, `RENTER='renter'` role constants.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Run the new migration | `cd backend && php artisan migrate` | runs `add_personal_qr_columns_to_user_profile_table` |
| Backend tests (Access) | `cd backend && php artisan test --filter=Access` | all pass |
| Backend tests (personal QR) | `cd backend && php artisan test --filter=PersonalQr` | new tests pass |
| Backend lint | `cd backend && vendor/bin/pint Modules/Access Modules/UserProfile` | exit 0 |
| Inspect route | `cd backend && php artisan route:list --path=access/my-qr` | shows GET access/my-qr |
| Frontend typecheck | `cd frontend && pnpm build` (runs `tsc -b`) | exit 0 |
| Frontend lint | `cd frontend && pnpm lint` | no NEW errors vs baseline |
| Frontend access tests | `cd frontend && pnpm test src/modules/access` | all pass |

## Scope

**In scope (backend)**:
- NEW migration `Modules/UserProfile/database/migrations/*_add_personal_qr_columns_to_user_profile_table.php`
- `backend/Modules/UserProfile/app/Models/UserProfile.php` (fillable + cast + auditExclude)
- `backend/Modules/Access/app/Services/AccessService.php` (resolveMyQr, generateUniquePersonalToken, scanQr personal branch, handlePersonalScan)
- `backend/Modules/Access/app/Http/Controllers/AccessLogController.php` (`myQr()` action)
- `backend/Modules/Access/routes/api.php` (route)
- (optional) `backend/database/seeders/CatalogSeeder.php` + a new const on `AccessVerification` IF a distinct "resident_self" verification is chosen (see open questions; default = reuse `AccessVerification::QR`)
- NEW tests under `backend/Modules/Access/tests/Feature/` (or `tests/Feature/Access/`)

**In scope (frontend)**:
- `frontend/src/modules/access/schema.ts` (`IMyQr` interface)
- `frontend/src/modules/access/services/AccessResource.ts` (`myQr` custom method)
- NEW `frontend/src/modules/access/MyQrPage.tsx`
- `frontend/src/App.tsx` (route)
- `frontend/src/shared/hooks/useNavSections.ts` (fixedNavItem)

**Out of scope**:
- The `qr_token` privacy hardening for VISIT tokens (`$hidden`, `searchableBy`) — that is a separate security plan; do NOT bundle it here. (When that plan lands, it must also add `personal_qr_token` to `$hidden` on `UserProfile`; note it there.)
- The guard-scanner UX (camera, scan-first caseta) — separate UX plan.
- The EVENT/TIME_BASED visitor-form `qr_expires_at` fix — separate frontend plan.
- Any new module/permission/menu CSV rows — "Mi QR" is a self-page (auth-only), so none are needed.
- A cron / scheduled rotation — regeneration is lazy on read by design.

## Git workflow

- Branch: `advisor/048-personal-rotating-qr`. Commits land in the respective submodule.
- Conventional commit, NO AI attribution / NO Co-Authored-By (repo rule).
- The pre-commit hook may need `--no-verify`; commits sign via 1Password (use `--no-gpg-sign --no-verify` if it fails in a headless context).
- Do NOT push or open a PR unless the operator instructs it.

## Steps

### Step 1: Migration — add two columns to `user_profile`

Create `Modules/UserProfile/database/migrations/2026_06_22_000000_add_personal_qr_columns_to_user_profile_table.php`:
```php
public function up(): void
{
    Schema::table('user_profile', function (Blueprint $table): void {
        $table->string('personal_qr_token', 64)->nullable()->index()
            ->comment('Live rotating personal entry credential (RES- prefixed); regenerated every 60s on read. Treat as a secret.');
        $table->timestamp('personal_qr_expires_at')->nullable()
            ->comment('UTC expiry of personal_qr_token; when past, the next /access/my-qr read rotates the token.');
    });
}

public function down(): void
{
    Schema::table('user_profile', function (Blueprint $table): void {
        $table->dropColumn(['personal_qr_token', 'personal_qr_expires_at']);
    });
}
```
**Verify**: `cd backend && php artisan migrate` runs cleanly; `php artisan db:show --counts` or `database-schema` shows the two new columns on `user_profile`.

### Step 2: UserProfile model — fillable, cast, audit-exclude the credential

In `Modules/UserProfile/app/Models/UserProfile.php`:
- Add `'personal_qr_token'` and `'personal_qr_expires_at'` to `$fillable`.
- Add `'personal_qr_token'` to `$auditExclude` (it is a live secret, like the PII already excluded).
- In `casts()` add `'personal_qr_expires_at' => 'datetime'`.

**Verify**: `cd backend && vendor/bin/pint Modules/UserProfile` exits 0.

### Step 3: AccessService — personal-token generator + lazy resolver returning seconds_remaining

In `AccessService.php` add a constant and two methods (TTL = 60s):
```php
public const PERSONAL_QR_PREFIX = 'RES-';
private const PERSONAL_QR_TTL_SECONDS = 60;

/**
 * Resolve the caller's personal rotating QR for the CURRENT tenant. Lazily
 * rotates when missing/expired. Returns the token, ISO expiry, and the
 * SERVER-authoritative countdown. firstOrCreate covers users with no profile
 * row yet in this tenant.
 *
 * @return array{token: string, expires_at: string, seconds_remaining: int}
 */
public function resolveMyQr(User $user): array
{
    $profile = UserProfile::firstOrCreate(
        ['user_id' => $user->id, 'tenant_id' => tenant('id')],
    );

    $expired = $profile->personal_qr_token === null
        || $profile->personal_qr_expires_at === null
        || $profile->personal_qr_expires_at->isPast();

    if ($expired) {
        $profile->personal_qr_token = $this->generateUniquePersonalToken();
        $profile->personal_qr_expires_at = Carbon::now()->addSeconds(self::PERSONAL_QR_TTL_SECONDS);
        $profile->save();
    }

    $remaining = max(0, (int) Carbon::now()->diffInSeconds($profile->personal_qr_expires_at, false));

    return [
        'token' => $profile->personal_qr_token,
        'expires_at' => $profile->personal_qr_expires_at->toIso8601String(),
        'seconds_remaining' => $remaining,
    ];
}

/** RES-XXXX-XXXX token, unique within the current tenant. Bounded loop. */
private function generateUniquePersonalToken(): string
{
    for ($i = 0; $i < 10; $i++) {
        $token = self::PERSONAL_QR_PREFIX.strtoupper(Str::random(4).'-'.Str::random(4));
        if (! UserProfile::where('personal_qr_token', $token)->exists()) {
            return $token;
        }
    }
    throw new \RuntimeException('No se pudo generar un token único de QR personal.');
}
```
Add `use Modules\UserProfile\Models\UserProfile;` to the imports. (Cross-module use of UserProfile from Access is acceptable — Access already depends on Housing's `UnitUser`.)

**Verify**: `cd backend && vendor/bin/pint Modules/Access` exits 0. A quick tinker/test asserts two calls within 60s return the SAME token with a decreasing `seconds_remaining`, and a call after expiry returns a DIFFERENT token.

### Step 4: scanQr — branch personal tokens to a self-entry path

At the TOP of `AccessService::scanQr` (before the `Visitor::where('qr_token', ...)` lookup at line 75), add a deterministic prefix branch INSIDE the existing transaction:
```php
return DB::transaction(function () use ($token, $context): array {
    if (str_starts_with($token, self::PERSONAL_QR_PREFIX)) {
        return $this->handlePersonalScan($token, $context);
    }
    // ... existing visitor lookup + state machine unchanged ...
```
Add `handlePersonalScan`:
```php
/**
 * Resident self-entry via personal rotating QR. Resolves the profile by token
 * in the current tenant, rejects if missing/expired, runs the blacklist check
 * for parity, and stamps an access_log row with NO visitor_id (already nullable).
 *
 * @param array<string,mixed> $context
 * @return array{valid:bool,reason:string|null,visitor:null,access_log:AccessLog|null,blacklist_match:bool}
 */
private function handlePersonalScan(string $token, array $context): array
{
    $profile = UserProfile::where('personal_qr_token', $token)->first();

    if (! $profile || $profile->personal_qr_expires_at === null || $profile->personal_qr_expires_at->isPast()) {
        return ['valid' => false, 'reason' => 'QR personal expirado o inválido', 'visitor' => null, 'access_log' => null, 'blacklist_match' => false];
    }

    $resident = User::withoutGlobalScope(UserTenantScope::class)->find($profile->user_id);
    $residentName = $resident?->name ?? 'Residente';

    // Active unit of the resident in this tenant (null if none / multi — see open question).
    $unitId = UnitUser::query()
        ->where('user_id', $profile->user_id)
        ->whereNull('end_date')
        ->value('unit_id');

    if ($this->checkBlacklist($residentName, null)) {
        $deniedLog = $this->denyEntry([
            'visitor_id' => null,
            'unit_id' => $unitId,
            'visitor_name' => $residentName,
            'access_entry_type_id' => AccessEntryType::QR,
            'access_verification_id' => AccessVerification::QR,
            'guard_user_id' => $context['guard_user_id'] ?? null,
        ]);

        return ['valid' => false, 'reason' => 'Residente en lista negra', 'visitor' => null, 'access_log' => $deniedLog, 'blacklist_match' => true];
    }

    $accessLog = AccessLog::create([
        'visitor_id' => null,
        'unit_id' => $unitId,
        'visitor_name' => $residentName,
        'access_entry_type_id' => AccessEntryType::QR,
        'access_verification_id' => AccessVerification::QR, // or a dedicated resident_self verification — see open questions
        'authorized_by' => null,
        'entered_at' => now(),
        'guard_user_id' => $context['guard_user_id'] ?? null,
    ]);

    // Personal QR rotates; it is NOT consumed (no status flip).
    return ['valid' => true, 'reason' => null, 'visitor' => null, 'access_log' => $accessLog, 'blacklist_match' => false];
}
```
Add `use App\Models\Scopes\UserTenantScope;` and `use Modules\Housing\Models\UnitUser;` if not already imported.

**Verify**: the response envelope keys EXACTLY match the visit-scan envelope (`valid`, `reason`, `visitor`, `access_log`, `blacklist_match`) so `ScanQrModal` renders without changes. `visitor` is null on a personal scan; the success screen falls back to `access_log.visitor_name` (confirm `ScanQrModal` does not hard-require `visitor`).

### Step 5: Controller + route — self-scoped GET /api/access/my-qr

Add to `AccessLogController`:
```php
/**
 * §14.2 (personal): the caller's own rotating entry QR. Self-scoped — any
 * authenticated resident may fetch THEIR OWN QR; no read.access_log required.
 */
public function myQr(Request $request): JsonResponse
{
    return response()->json([
        'data' => app(AccessService::class)->resolveMyQr($request->user()),
    ]);
}
```
In `routes/api.php`, inside the existing `Route::middleware($customMiddleware)->group(...)` block (the one at lines 72-83):
```php
Route::get('access/my-qr', [AccessLogController::class, 'myQr']);
```
Use `$customMiddleware` (NOT `$tenancyMiddleware`) to match the other self-scoped actions — it rejects refresh tokens + inactive users.

**Verify**: `cd backend && php artisan route:list --path=access/my-qr` shows `GET api/access/my-qr` with the custom middleware. No `$keyForPermission`/authorize gate on the method.

### Step 6: Frontend — interface, resource method

In `schema.ts` add:
```ts
/** Respuesta de GET /access/my-qr (QR personal rotativo). El countdown es autoritativo del servidor. */
export interface IMyQr {
  token: string
  expires_at: string
  seconds_remaining: number
}
```
In `AccessResource.ts`, add to `AccessLogResource` customMethods + its generic signature:
```ts
myQr: () => Promise<IMyQr>
// ...
myQr: async () => {
  const { data } = await apiClient.get<{ data: IMyQr }>('/access/my-qr')
  return data.data
},
```
(import `IMyQr` from the schema.)

**Verify**: `cd frontend && pnpm build` exits 0.

### Step 7: Frontend — MyQrPage with server-clock countdown

Create `frontend/src/modules/access/MyQrPage.tsx`:
- Fetch with TanStack Query: `useQuery({ queryKey: ['my-qr'], queryFn: AccessLogResource.myQr, refetchOnWindowFocus: true })`.
- Render the token as a large QR via `QRCodeSVG` (reuse the style from `VisitorQrModal.tsx`: white bg, size ~240-280, level `'M'` or `'Q'`).
- Countdown is DISPLAY-ONLY, seeded from `data.seconds_remaining`: a `useState` decremented by a `setInterval(1000)`; when it reaches 0, call `refetch()` (which returns the freshly-rotated token + a new `seconds_remaining` that re-seeds the display).
- Re-seed the local counter from `data.seconds_remaining` whenever `data` changes (effect on `data`).
- Refetch on `visibilitychange` (tab return) so a backgrounded tab shows a fresh QR.
- Show the human-readable code and an SVG ring (`dasharray = remaining / 60`) for premium UX.
- All timing authority is the server value; NEVER compute remaining from `expires_at` on the client.
- Spanish UI strings (project rule): heading "Mi QR de acceso", helper "Este código cambia cada minuto. Muéstralo en la caseta.".

### Step 8: Frontend — route + sidebar entry (resident self-page)

In `App.tsx`, add `/my-qr` near `/my-access-log` (same guard: under `PermissionRoute permissionEntity="unit"`, which residents have via `read.unit`):
```tsx
<Route path="/my-qr" element={<MyQrPage />} />
```
In `useNavSections.ts`, add a `fixedNavItems` entry gated to `[ERoleUserSlug.RESIDENT, ERoleUserSlug.RENTER]` (mirror the existing role-gated items): label "Mi QR", path `/my-qr`, an icon from `iconMap.ts` (e.g. a QR/scan icon).

**Verify**: `cd frontend && pnpm build` exits 0 and `pnpm lint` shows no new errors. Logged in as a resident, "Mi QR" appears in the sidebar and `/my-qr` renders a rotating QR.

## Test plan

Backend (`PersonalQr` feature tests):
- `resolveMyQr` is idempotent within the TTL: two calls < 60s apart return the SAME token; `seconds_remaining` strictly decreases and is ≤ 60.
- After forcing `personal_qr_expires_at` into the past, the next call returns a DIFFERENT token and `seconds_remaining` ≈ 60.
- `firstOrCreate`: a user with NO `user_profile` row in the tenant gets one created on first `/access/my-qr` call.
- `scanQr` with a `RES-` token of a valid, non-expired profile returns `valid:true`, creates an `access_log` with `visitor_id=null`, `visitor_name=<resident>`, `entered_at` set; does NOT touch any visitor row.
- `scanQr` with a `RES-` token whose expiry is past returns `valid:false` `reason:'QR personal expirado o inválido'` and creates NO log.
- Blacklisted resident name → `valid:false`, `blacklist_match:true`, a DENIED log row.
- Tenant isolation: a `RES-` token from tenant A is NOT resolvable while tenant B is initialized (UserProfile is `BelongsToTenant`).
- `GET /access/my-qr` works for an authenticated resident WITHOUT `read.access_log`; a refresh token / inactive user is rejected by `$customMiddleware`.

Frontend:
- `pnpm test src/modules/access` green.
- Optional component test for `MyQrPage`: mock `myQr` to return `seconds_remaining: 2`; assert the counter ticks and `refetch` fires at 0.

## Done criteria

ALL must hold:

- [ ] `cd backend && php artisan migrate` adds the two columns (verified via schema)
- [ ] `cd backend && php artisan test --filter=PersonalQr` passes
- [ ] `cd backend && php artisan test --filter=Access` still passes (no regression in visit scanning)
- [ ] `cd backend && vendor/bin/pint Modules/Access Modules/UserProfile` exits 0
- [ ] `cd backend && php artisan route:list --path=access/my-qr` shows the self-scoped GET route
- [ ] `cd frontend && pnpm build` exits 0
- [ ] `personal_qr_token` is in `UserProfile::$auditExclude`
- [ ] The scan envelope from a personal scan has the SAME keys as a visit scan (`ScanQrModal` unchanged and renders)
- [ ] No new module/permission/menu CSV rows added (self-page)
- [ ] No files outside the in-scope list modified (`git status` in both submodules)
- [ ] `plans/README.md` status row updated

## STOP conditions

- `ScanQrModal` (frontend) hard-requires `result.visitor` (e.g. dereferences `visitor.name` without a fallback) — STOP and either add a small `visitor_name` fallback to the success screen (then note it as an added in-scope file) or report, rather than silently breaking the personal-scan success render.
- The multi-unit decision (open question) is unresolved AND a resident in the test data belongs to 2+ active units — STOP and get the product decision before choosing `unit_id` semantics; do not guess.
- A pre-existing `php artisan test --filter=Access` failure exists on the base commit — report it; do not fix out-of-scope tests.
- The drift check shows in-scope files changed since `fb2062b`/`0887d5a` and the excerpts no longer match.

## Maintenance notes

- The personal token is a LIVE credential. When the visit-token privacy plan adds `$hidden`/serialization rules, it MUST also add `personal_qr_token` to `$hidden` on `UserProfile` so it is never accidentally serialized (today it is only ever returned via the dedicated `/access/my-qr` array, never an Orion model payload, but a future `UserProfile` Orion resource would leak it).
- TTL lives in `AccessService::PERSONAL_QR_TTL_SECONDS` (60). If product later wants a "rotate now" button, add a `POST /access/my-qr/rotate` that forces regeneration; do not shorten the TTL globally.
- Regeneration is lazy/on-read — NEVER add a cron for this. The previous token simply becomes unresolvable once `personal_qr_expires_at` passes.
- If reports/bitácora need to distinguish resident self-entries from visitor entries, add a dedicated `AccessVerification` (e.g. `resident_self`) via `CatalogSeeder` + a const UUID on the model, and use it in `handlePersonalScan` instead of `AccessVerification::QR` (see open questions).
- Keep `handlePersonalScan` inside the same `scanQr` transaction so the blacklist check + log creation are atomic, matching the visit path.