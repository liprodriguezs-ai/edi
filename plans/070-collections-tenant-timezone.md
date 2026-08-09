# Plan 070: Make Collections resolve "today" in the cluster's timezone, not the server's UTC

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- \
>   Modules/Collections/app/Services/ChargeStatusService.php \
>   Modules/Collections/app/Services/ChargePolicyService.php \
>   Modules/Collections/app/Console/Commands/GenerateMonthlyChargesCommand.php
> ```
> If any of those changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (plan 068 makes the verification trustworthy in CI; not a hard block)
- **Category**: bug
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

Every tenant in this product is a Mexican residential cluster, and `tenant.timezone`
defaults to `America/Mexico_City` (UTC−6). The app server runs UTC
(`config/app.php:70` → `env('APP_TIMEZONE', 'UTC')`). Collections resolves "today"
with bare `Carbon::today()`, so **between 18:00 and midnight local time the
server is already on the next calendar day**.

That means a charge whose `due_date` is today flips to `OVERDUE` up to six hours
before it is actually late, and the late-fee surcharge gets stamped with the
wrong date.

This used to be cosmetic. It is not any more: the new delinquency-restriction
feature keys off exactly this status, so an early flip now **blocks the resident
from amenities, visitor QR codes and package pickup — over money that is not yet
due**. Collections is the only money module in the repo that ignores the
`tenant.timezone` column the product exposes in the cluster's own Configuration
screen.

## Current state

### The divergent pattern

Four other modules already resolve the tenant's timezone, using an identical
one-line helper. This is the house pattern to copy:

```php
// backend/Modules/Access/app/Services/AccessService.php:701-707
    /**
     * Resolve the active tenant's timezone, falling back to the app default.
     * QR expiry end-of-day must land at the cluster's local midnight, not UTC.
     */
    private function tenantTimezone(): string
    {
        return tenant('timezone') ?: config('app.timezone');
    }
```

The same helper exists at
`backend/Modules/AmenityReservations/app/Services/ReservationService.php:1168`,
and the pattern is inlined at
`backend/Modules/Treasury/app/Http/Controllers/ExpenseController.php:113` and
`backend/Modules/Access/app/Services/AccessService.php:235`.

**`grep -rn "tenant('timezone')" backend/Modules/Collections` returns nothing.**

### The call sites to change (7)

**1. The cron — worst of the set, because `$today` is computed *before* the
tenant loop and shared across every tenant regardless of their timezone:**

```php
// backend/Modules/Collections/app/Console/Commands/GenerateMonthlyChargesCommand.php:78-105
    public function handle(PeriodChainService $periodChain): int
    {
        $today = Carbon::today();

        try {
            $catchUpFrom = $this->resolveCatchUpFrom($today);
        } catch (\InvalidArgumentException $e) {
        ...
        $tenants = Tenant::all();
        ...
        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($periodChain, $today, $catchUpFrom, $cap, $tenant, $autoPeriodicities): void {
                $generationDay = (int) (Setting::getValue(CollectionSettings::GENERATION_DAY) ?? CollectionSettings::DEFAULT_GENERATION_DAY);

                $this->generateForTenant($periodChain, $today, $catchUpFrom, $cap, $tenant, $generationDay, $autoPeriodicities);
            });
        }
```

**2. The status FSM:**

```php
// backend/Modules/Collections/app/Services/ChargeStatusService.php:280-281
    public function resolveStatusKey(Charge $charge, ?int $unitCreditRaw = null, ?array $thresholds = null): string
    {
        $today = Carbon::today();
```

**3. The surcharge write-side stamp:**

```php
// backend/Modules/Collections/app/Services/ChargeStatusService.php:417
        $today = Carbon::today()->toDateString();
```

**4. The surcharge computation:**

```php
// backend/Modules/Collections/app/Services/ChargeStatusService.php:441
        $today = Carbon::today();
```

**5–7. Policy period anchoring and the eventual-batch default date:**

```php
// backend/Modules/Collections/app/Services/ChargePolicyService.php:143-145
        $anchor = $policy->valid_from
            ? Carbon::parse($policy->valid_from)
            : Carbon::today();
```

```php
// backend/Modules/Collections/app/Services/ChargePolicyService.php:187
                Carbon::today(),
```

```php
// backend/Modules/Collections/app/Services/ChargePolicyService.php:245
        $issuedAt = $attributes['issued_at'] ?? Carbon::today()->toDateString();
```

### Repo conventions that apply

- Conventional commits, no AI attribution.
- Collections services are plain classes resolved from the container; helpers are
  `private` methods on the service (see `ChargeStatusService::resolveThresholds`,
  `ChargePolicyService::generationDay`).
- `tenant('timezone')` is only meaningful **inside** an initialized tenancy
  context. In the cron that means inside `$tenant->run(...)`; in services called
  from an HTTP request the `initializeTenancy` middleware has already run.
- Tests: `backend/tests/Feature/Collections/`. `Carbon::setTestNow()` is the
  established way to pin time.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Targeted tests | `cd backend && php artisan test --filter=Collections` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Find stragglers | `grep -rn "Carbon::today()" backend/Modules/Collections` | see Done criteria |

## Scope

**In scope** (the only files you should modify or create):
- `backend/Modules/Collections/app/Support/CollectionsClock.php` (create — see Step 1)
- `backend/Modules/Collections/app/Services/ChargeStatusService.php`
- `backend/Modules/Collections/app/Services/ChargePolicyService.php`
- `backend/Modules/Collections/app/Console/Commands/GenerateMonthlyChargesCommand.php`
- `backend/tests/Feature/Collections/CollectionsTimezoneBoundaryTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `backend/config/app.php` — do NOT change `APP_TIMEZONE`. The server storing UTC
  is correct; only the *interpretation* of "today" is wrong. Changing the app
  timezone would silently reinterpret every stored timestamp in the database.
- `PeriodicityScheduleResolver` and `PeriodChainService` — they receive an anchor
  date from their callers and do date arithmetic on it. Once the callers pass a
  tenant-local date, these are correct. Do not add timezone logic inside them.
- Any other module's date handling. Access, AmenityReservations, Treasury and
  Package already do this correctly.
- `Carbon::now()` at `ChargePolicyService.php:391` — that is a soft-delete
  timestamp (a true instant, not a calendar day). Instants are correctly UTC.
  Leave it.
- Any change to how dates are **stored**. Columns stay as they are.

## Git workflow

- Branch: `advisor/070-collections-tenant-timezone`
- Suggested commit: `fix(collections): resolve "today" in the tenant timezone, not server UTC`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add a single shared clock helper

Create `backend/Modules/Collections/app/Support/CollectionsClock.php`, namespace
`Modules\Collections\Support`. A small final class with static methods:

- `public static function timezone(): string` — returns
  `tenant('timezone') ?: config('app.timezone')`, matching
  `AccessService::tenantTimezone()` exactly.
- `public static function today(): \Carbon\CarbonImmutable|\Carbon\Carbon` —
  returns `Carbon::today(self::timezone())`. Match the Carbon class the rest of
  Collections uses (read the `use` statements in `ChargeStatusService.php`
  first and use the same one).

Docblock must state: Collections compares calendar days (due dates, grace
windows, period boundaries) against "today". Those comparisons must happen in
the cluster's civil calendar, because a `due_date` is a civil date, not an
instant. The server runs UTC; a bare `Carbon::today()` rolls over ~6 hours early
for `America/Mexico_City`, which flips charges to `OVERDUE` before they are due
and — since the delinquency restrictions — blocks residents from amenities,
visitor QR and package pickup a day early.

A single shared helper (rather than repeating the ternary) is deliberate: it is
the thing to grep for when auditing this class of bug later.

**Verify**: `cd backend && php -l Modules/Collections/app/Support/CollectionsClock.php`
→ "No syntax errors detected"

### Step 2: Convert `ChargeStatusService` (3 sites)

Replace at `ChargeStatusService.php:281`, `:417` and `:441`:

- `:281` `$today = Carbon::today();` → `$today = CollectionsClock::today();`
- `:417` `$today = Carbon::today()->toDateString();` → `$today = CollectionsClock::today()->toDateString();`
- `:441` `$today = Carbon::today();` → `$today = CollectionsClock::today();`

Add the `use Modules\Collections\Support\CollectionsClock;` import.

Do not change any surrounding comparison logic — the `lessThanOrEqualTo`,
`addDays($graceDays)` and string-equality checks are all correct once `$today`
is right.

**Verify**: `grep -n "Carbon::today()" backend/Modules/Collections/app/Services/ChargeStatusService.php`
→ no matches.

### Step 3: Convert `ChargePolicyService` (3 sites)

Replace at `:145`, `:187` and `:245` with `CollectionsClock::today()` (keep
`->toDateString()` at `:245`). Add the import.

Leave `Carbon::now()` at `:391` untouched — see Scope.

**Verify**: `grep -n "Carbon::today()" backend/Modules/Collections/app/Services/ChargePolicyService.php`
→ no matches. And `grep -c "Carbon::now()" backend/Modules/Collections/app/Services/ChargePolicyService.php`
→ `1` (still there).

### Step 4: Move the cron's `$today` inside the tenant loop

This is the substantive change, not a mechanical swap. Today `$today` is computed
once at `handle()` line 80 and handed to every tenant. Each tenant may be in a
different timezone, so a per-tenant date must be resolved **inside**
`$tenant->run(...)` where tenancy is initialized.

Restructure `handle()` so that:

- `$today` at line 80 is **kept** but renamed to something explicitly
  server-scoped (e.g. `$serverToday`) and used **only** for
  `resolveCatchUpFrom($serverToday)`, which validates the operator-supplied
  `--from` flag against the `MAX_CATCHUP_DAYS` ceiling. That check is an
  operator-input guard, not a billing decision, so a server-side date is
  acceptable there — but add a one-line comment saying exactly that, so the next
  reader does not "fix" it.
- Inside the `$tenant->run(...)` closure, resolve
  `$tenantToday = CollectionsClock::today();` and pass **that** into
  `generateForTenant(...)` in place of the hoisted `$today`.

Update the closure's `use (...)` list accordingly (drop `$today`, since it is no
longer needed inside).

Add a comment above the closure recording why the date is resolved per tenant:
tenants can sit in different timezones, and a period boundary is a civil date in
the cluster's calendar.

**Verify**:
```
grep -n "CollectionsClock::today()" backend/Modules/Collections/app/Console/Commands/GenerateMonthlyChargesCommand.php
```
→ at least one match, and it must appear **after** the `foreach ($tenants` line.
Confirm by reading the file that the call is lexically inside the
`$tenant->run(function () ...)` closure.

### Step 5: Write the boundary test

Create `backend/tests/Feature/Collections/CollectionsTimezoneBoundaryTest.php`.
Read an existing test in that directory first (e.g.
`backend/tests/Feature/Collections/ChargePolicyScopeUpdateTest.php`) and follow
its tenant/charge fixture setup and its `use` of the shared test traits.

Cover:

1. **The regression this plan fixes.** Set the tenant's `timezone` to
   `America/Mexico_City`. Pin time with
   `Carbon::setTestNow(Carbon::parse('2026-08-04 23:30:00', 'UTC'))` — which is
   17:30 on 2026-08-04 in Mexico City, still the same civil day. Create a charge
   with `due_date = 2026-08-05` and a grace of 0. Assert the resolved status is
   **not** `OVERDUE` (it should be `due_soon` or `pending` depending on
   thresholds — assert the specific key your fixture produces, and assert
   explicitly that it is not `overdue`).

   Before this plan, UTC "today" would already be 2026-08-05 at that instant.

2. **The genuinely-overdue case still works.** Same setup, time pinned to a UTC
   instant that is unambiguously past `due_date + grace` in Mexico City. Assert
   the status **is** `OVERDUE`. This proves the fix did not simply delay
   everything by a day.

3. **A tenant in a different timezone gets a different answer at the same
   instant.** Create two tenants, one `America/Mexico_City` and one
   `America/Tijuana` (UTC−7), each with an equivalent charge, and assert at a
   pinned instant that falls between their two local midnights that their
   resolved statuses differ as expected. This is the test that would have caught
   the hoisted-`$today` bug in the cron.

Always `Carbon::setTestNow()` back to `null` in `tearDown`.

**Verify**: `cd backend && php artisan test --filter=CollectionsTimezoneBoundary`
→ all pass.

### Step 6: Confirm no regressions across Collections

```
pgrep -f "artisan test"    # must print nothing
cd backend && php artisan test --filter=Collections
```

**Verify**: the suite passes. Compare the counts against a run from before your
change if you have one; report any delta.

## Test plan

- **New tests**: `CollectionsTimezoneBoundaryTest` — 3 cases as described in
  Step 5 (early-evening no-false-overdue, genuinely-overdue still overdue,
  two-timezone divergence).
- **Structural pattern**: model on
  `backend/tests/Feature/Collections/ChargePolicyScopeUpdateTest.php`.
- **The critical assertion style**: assert on the *resolved status key*, not on a
  timestamp. The bug is a misclassification, and a timestamp assertion would pass
  for the wrong reason.
- **Verification**: `php artisan test --filter=Collections` — all pass, including
  the 3 new tests.

## Done criteria

ALL must hold:

- [ ] `grep -rn "Carbon::today()" backend/Modules/Collections` returns **no matches**
- [ ] `grep -rn "CollectionsClock::today()" backend/Modules/Collections | wc -l` returns `7` or more
- [ ] `backend/Modules/Collections/app/Support/CollectionsClock.php` exists
- [ ] In `GenerateMonthlyChargesCommand.php`, the `CollectionsClock::today()` call appears lexically inside the `$tenant->run(...)` closure (confirm by reading, not only grepping)
- [ ] `cd backend && php artisan test --filter=CollectionsTimezoneBoundary` passes
- [ ] `cd backend && php artisan test --filter=Collections` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the 5 files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Existing Collections tests fail after Step 2 or Step 3 with off-by-one-day
  assertions.** That is meaningful: it may mean a test encoded the UTC behavior
  as expected. Do **not** adjust the test to match the new behavior without
  reporting — the maintainer needs to know that a documented expectation
  changed. Report which test and which date it asserts.
- **You find a Collections call site that compares a stored *instant* (a
  `created_at`, a `paid_at`) against `CollectionsClock::today()`.** Instants and
  civil dates must not be mixed. Report the site rather than converting it.
- **`tenant('timezone')` returns null or empty in the cron path.** That would
  mean tenancy is not initialized where you placed the call. The fallback to
  `config('app.timezone')` would silently restore the old buggy behavior for
  every tenant. Verify the call really is inside `$tenant->run(...)` and report
  if it is not resolvable there.
- **Tests fail with `SQLSTATE 42P01` / `42703`, or with catalog duplicate-key
  errors.** Per `AGENTS.md` these are the known shared-test-database and
  non-idempotent-seeder problems, not your regression. Run
  `pgrep -f "artisan test"`, kill stragglers, re-run once. If it persists,
  report and stop.
- **A tenant is found with a `timezone` value Carbon cannot parse.** Report it;
  do not add a silent try/catch that falls back to UTC — that reintroduces the
  bug for that tenant.

## Maintenance notes

- **The first run after deploy will reclassify charges.** Some charges currently
  marked `OVERDUE` in the early-evening window will correctly move back to
  `due_soon`/`pending`, and unit-level aggregate statuses recompute worst-wins
  from there. That is the fix working, but it is a visible data movement —
  worth telling the operator before deploying, and worth running the daily
  status command once manually and diffing the counts.
- **Delinquency restrictions are downstream of this.** Residents who were
  wrongly blocked from amenities / visitor QR / package pickup will regain
  access. That is the point, but support should know.
- **What a reviewer should scrutinize**: Step 4 only. Steps 2 and 3 are
  mechanical; Step 4 changes control flow, and getting the `use (...)` list
  wrong there produces a subtle bug where every tenant silently shares the first
  tenant's date.
- **Deliberately deferred**: `CollectionsClock` is scoped to Collections on
  purpose. Access, AmenityReservations and Treasury each have their own
  equivalent helper. Consolidating all four into one shared `core/` helper is a
  reasonable follow-up, but doing it in this plan would widen the blast radius
  from one module to four for no correctness gain.
- **If a future module needs "today"**, point it at this helper's docblock rather
  than letting it reach for `Carbon::today()`.
