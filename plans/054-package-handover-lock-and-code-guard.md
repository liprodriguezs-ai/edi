# Plan 054: Lock packages during handover and require a READY package for the pickup code

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/Package/app/Services/PackageService.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (concurrency) + security
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

Two problems in the package handover path, both confirmed by reading the service:

1. **No row lock (custody race).** The handover transaction loads the packages with a
   plain `get()` (no `lockForUpdate`), checks they're READY, then writes PICKED_UP.
   Under Postgres READ COMMITTED, two concurrent `POST /package/handover` for the same
   package both read READY and both proceed — the second overwrites
   `picked_up_by_user_id` / `pickup_proxy_name` / `pickup_proxy_relationship` and a
   second "Paquete retirado" notification fires. For a chain-of-custody feature, the
   record of WHO collected the package is corruptible under a race.

2. **Pickup code accepts a terminal/stale package.** When `require_pickup_code` is on,
   handover resolves the code with `Package::where('pickup_code', $code)->first()` and
   only checks the code's UNIT matches — no status filter. Codes are never rotated on
   pickup and are preserved across reassignment, so a code from a long-picked-up package
   still satisfies the "code required" gate for NEW packages of that unit. `handover`
   can be called directly, bypassing the `verify-code` scan that DOES reject stale codes
   — the two endpoints enforce different rules, defeating the anti-fraud control.

## Current state

`backend/Modules/Package/app/Services/PackageService.php`, inside `handover(...)`:

- Code branch (lines 300–327), when `require_pickup_code` is on:
  ```php
  $code = trim((string) ($data['pickup_code'] ?? ''));
  if ($code === '') { abort(422, 'Se requiere el código de retiro (campo: pickup_code)'); }

  // Resolve the code to a unit using the same lookup as verifyPickupCode.
  $codePackage = Package::where('pickup_code', $code)->first();   // <-- NO status filter
  if (! $codePackage) { abort(422, 'El código de retiro no es válido'); }
  $resolvedUnitId = $codePackage->unit_id;
  // ... then only checks all $packageIds share $resolvedUnitId ...
  ```
- Transaction (lines 329–351): the unlocked read-modify-write:
  ```php
  return DB::transaction(function () use ($packageIds, $data, $guard): Collection {
      $packages = Package::whereIn('id', $packageIds)->get();   // <-- no lockForUpdate
      if ($packages->count() !== count($packageIds)) { abort(422, '...no encontrados...'); }

      foreach ($packages as $pkg) {
          if ($pkg->package_status_id !== PackageStatus::READY) {
              abort(422, "El paquete {$pkg->id} no está disponible para entrega");
          }
      }
      // ... then sets picked_up_* and PICKED_UP and saves ...
  });
  ```
- `verifyPickupCode` (≈236–239) DOES reject codes on terminal packages — proof the
  intended rule is "code must belong to an active/READY package"; handover just skips it.
- `PackageStatus::READY` / `::PICKED_UP` are catalog constants on the `PackageStatus`
  model.

Convention: `returnPackage`/`flagIncident`/`reassignUnit` (≈398/429/463) share the same
unlocked read-modify-write; this plan fixes handover, and Step 3 optionally extends the
lock to those (kept small — see Scope).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/Package/app/Services/PackageService.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=Package` | all pass incl. new cases |

> Host php, shared `testing` DB, ONE process at a time. Package has real tests
> (`PackagePickupTest`, `PackageLifecycleTest`) — but note they may run only via a path
> filter until plan 063 wires module tests into phpunit; `--filter=Package` works
> regardless.

## Scope

**In scope**:
- `PackageService.php` — `handover()`: add `lockForUpdate()` + re-check, and add a
  READY-status filter to the pickup-code lookup.
- Package feature tests: add a code-status test and (if feasible in the harness) a
  concurrency/re-check assertion.

**Out of scope (this plan)**:
- Hashing the pickup code at rest (plaintext storage) — real but a separate, larger
  change (migration + verify path); note it in Maintenance, do not do it here.
- Rotating the code on pickup — desirable but out of this plan's minimal-fix scope;
  the READY-status guard already closes the stale-code hole. Note as follow-up.
- `returnPackage`/`flagIncident`/`reassignUnit` locking — OPTIONAL Step 3; if it grows
  the diff or tests, defer and note it.

## Steps

### Step 1: Lock the packages and re-assert READY inside the lock

```php
return DB::transaction(function () use ($packageIds, $data, $guard): Collection {
    $packages = Package::whereIn('id', $packageIds)->lockForUpdate()->get();

    if ($packages->count() !== count($packageIds)) {
        abort(422, 'Uno o más paquetes no fueron encontrados en este clúster');
    }

    foreach ($packages as $pkg) {
        if ($pkg->package_status_id !== PackageStatus::READY) {
            abort(422, "El paquete {$pkg->id} no está disponible para entrega");
        }
    }
    // ... unchanged write of picked_up_* + PICKED_UP ...
});
```

The re-check now runs against the locked rows, so a concurrent handover blocks until the
first commits and then fails the READY check.

**Verify**: `php -l` clean.

### Step 2: Require the pickup code to resolve to a READY package of the target unit

```php
$codePackage = Package::where('pickup_code', $code)
    ->where('package_status_id', PackageStatus::READY)
    ->first();

if (! $codePackage) {
    abort(422, 'El código de retiro no es válido');
}
```

This matches `verifyPickupCode`'s rule so both entry points agree.

**Verify**: `php -l` clean.

### Step 3 (optional, only if it stays small): extend the lock

If quick, apply the same `lockForUpdate()` + re-check to `returnPackage`,
`flagIncident`, and `reassignUnit`. If it grows the diff or breaks tests, DEFER and note
it in `plans/README.md` — do not let it block Steps 1–2.

### Step 4: Tests

- **Stale code rejected**: a unit with one PICKED_UP package (its code) and one READY
  package; call handover for the READY package supplying the PICKED_UP one's code →
  422 (before the fix this would pass the code gate).
- **Happy path unchanged**: valid READY package + its own code → success, status
  PICKED_UP, custody fields set.
- Concurrency: if the harness can't truly parallelize, at minimum assert the second
  handover of an already-PICKED_UP package returns 422 (the re-check).

**Verify**: `php artisan test --filter=Package` → all pass; the stale-code test fails against pre-fix code.

### Step 5: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] Handover loads packages `lockForUpdate()` and re-checks READY under the lock
- [ ] Pickup-code lookup filters `package_status_id = READY`
- [ ] `php artisan test --filter=Package` green with the new stale-code case
- [ ] Stale-code test fails against pre-fix code
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] Only `PackageService.php` + Package tests modified (unless Step 3 taken — note it)
- [ ] `plans/README.md` row updated

## STOP conditions

- The `handover` code differs from the excerpts (drift).
- `PackageStatus::READY` is not a real constant (grep the model) — use the real value.
- Adding `lockForUpdate` breaks an existing Package test in a way that implies handover
  is called inside another open transaction with a conflicting lock — report before
  forcing it.

## Maintenance notes

- Follow-ups explicitly deferred: (1) hash `pickup_code` at rest + update `verifyPickupCode`
  and this lookup to compare hashes; (2) rotate/clear the code on terminal transition so
  a code is single-use. Both are worth a dedicated plan.
- Reviewer: confirm the READY filter is on BOTH `verify-code` and `handover` so the two
  endpoints can't diverge again.
- If handover ever supports partial multi-unit batches, the single-unit code constraint
  and the lock set must be revisited.
