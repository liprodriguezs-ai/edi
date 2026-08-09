# Plan 040: CatalogSeeder is re-run-safe — kill the `chat_channel_type_pkey` duplicate-key that blocks the test suite

> **Executor instructions**: Follow step by step. Run every verification command
> and confirm the expected result before moving on. If a STOP condition fires,
> stop and report. When done, update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- database/seeders/CatalogSeeder.php phpunit.xml`
> On any mismatch with the "Current state" excerpts, treat as a STOP condition.

## Status

- **Priority**: P1 (blocks every broad `php artisan test` run → no green CI signal)
- **Effort**: S–M
- **Risk**: MED (touches a seeder all tests depend on — verify broadly)
- **Depends on**: none
- **Category**: tests / tech-debt
- **Planned at**: backend `fb2062b`, 2026-06-21

## Why this matters

Any broad backend test run (`php artisan test`, or a test class with 2+ methods)
fails dozens of tests with
`SQLSTATE[23505] duplicate key value violates unique constraint "chat_channel_type_pkey"`,
traced to `database/seeders/CatalogSeeder.php:402`. A single test method in
isolation passes. This means there is NO green full-suite signal today — every
plan in this repo has been verified test-by-test in isolation because of it. It
must be fixed for CI and for normal local test runs to work.

## Root cause (CONFIRMED empirically, 2026-06-21)

The advisor reproduced this in `php artisan tinker` against the `testing` DB:
running `(new CatalogSeeder)->run()` **twice in one process** throws the exact
duplicate-key — **with ZERO soft-deleted rows involved**. This DISPROVES the
"SoftDeletes hides the row" hypothesis. The real mechanism:

- All `CatalogSeeder::seedXxx()` methods use
  `Model::updateOrCreate(['id' => $fixedUuid], $row)`.
- `updateOrCreate` = `firstOrCreate`: it runs `SELECT ... WHERE id = X [scopes]`,
  and only if that returns nothing does it `INSERT`.
- The central catalog models (`ChatChannelType` etc.) extend
  `QCH\Core\Models\CentralCatalogModel`, which uses the `Cachable` trait
  (`genealabs/laravel-model-caching`). When model caching is active (config
  `laravel-model-caching.enabled`, store `redis` — see
  `backend/config/laravel-model-caching.php`), the first seed's lookup query
  result (or its empty pre-insert result) is **cached**, so the second seed's
  `SELECT id = X` is served a **stale "not found"** → it falls through to
  `INSERT` → duplicate primary key.
- In tests, `WithTenantSetup::seedTenant()` calls `(new CatalogSeeder)->run()` on
  every test (twice in `twoTenants()`), and transactionless tests
  (`$connectionsToTransact = []`, e.g. `ReportsCatalogTest`) COMMIT the rows so
  they survive into later tests in the same process → the stale-cache lookup
  recurs → the cascade of failures.
- Production seeds the central catalogs ONCE per deploy, so it does not hit this;
  it is a test-time defect surfaced by repeated re-seeding with model caching on.

## Current state

- `backend/database/seeders/CatalogSeeder.php` — ~20 private `seedXxx()` methods,
  all of this shape (lines 392-404 shown for `seedChatChannelTypes`):
  ```php
  private function seedChatChannelTypes(): void
  {
      $types = [
          ['id' => ChatChannelType::OFFICIAL, 'name' => 'Oficial', 'order' => 0, 'is_active' => true],
          ['id' => ChatChannelType::GENERAL, 'name' => 'General', 'order' => 1, 'is_active' => true],
          ['id' => ChatChannelType::CUSTOM, 'name' => 'Personalizado', 'order' => 2, 'is_active' => true],
          ['id' => ChatChannelType::DIRECT, 'name' => 'Directo (1:1)', 'order' => 3, 'is_active' => true],
      ];
      foreach ($types as $type) {
          ChatChannelType::updateOrCreate(['id' => $type['id']], $type);
      }
  }
  ```
  Row column sets vary per catalog (some have `color`, some have behaviour
  columns); all have `id`, `name`, `order`, `is_active`.
- `backend/config/laravel-model-caching.php` — `'enabled' => env('MODEL_CACHE_ENABLED', false)`, `'store' => env('MODEL_CACHE_STORE', 'redis')`. The file's own comment warns non-taggable stores "silently make the Cachable trait a no-op (false greens in tests)".
- `backend/phpunit.xml` — `<php>` env block sets `CACHE_STORE=array` etc., but does NOT set `MODEL_CACHE_ENABLED` or `MODEL_CACHE_STORE`, so model caching uses the config default (redis store, enabled per `.env`).
- `backend/tests/TestCase.php` — `ACCUMULATING_CENTRAL_TABLES` truncates only `user`, `model_has_roles`, `model_has_permissions` between transactionless tests; NOT the catalog tables.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Reproduce (before fix) | `php artisan test --filter=ReportsCatalogTest` | MANY fail with `chat_channel_type_pkey` |
| PHP syntax | `php -l database/seeders/CatalogSeeder.php` | No syntax errors |
| Format | `vendor/bin/pint database/seeders/CatalogSeeder.php` | exit 0 |
| Target green | `php artisan test --filter=ReportsCatalogTest` | all pass (after fix) |
| Broader green | `php artisan test --filter=Reports` then `--filter=Collections` | no `chat_channel_type_pkey`; no NEW failures vs baseline |

> Run backend tests from `backend/` (`cd backend && ...`).

## Scope

**In scope:**
- `backend/database/seeders/CatalogSeeder.php` (the seed methods → idempotent writes)
- `backend/phpunit.xml` (the secondary test-config change, Step 3)

**Check & possibly include (Step 2b):**
- `backend/Modules/*/database/seeders/TenantCatalogSeeder.php` (or equivalent) — if it uses the SAME `foreach { updateOrCreate(['id'=>...]) }` pattern on `TenantCatalogModel` (also `Cachable`), it has the same latent bug; apply the same fix. If it uses a different pattern, leave it and note so.

**Out of scope:**
- `tests/TestCase.php` truncation list (do NOT add catalog tables — `TRUNCATE ... CASCADE` on central catalogs would wipe FK-dependent tenant rows mid-suite; the seeder-level fix is safer).
- Any business logic; any relation work from plans 034-039.

## Git workflow

Submodule `backend/`. Branch `advisor/040-catalog-seeder-idempotent`. Conventional commit, no AI attribution. `--no-gpg-sign --no-verify` if signing/hooks fail. Do NOT push/merge unless instructed.

## Steps

### Step 0: Reproduce and confirm the mechanism

Run `php artisan test --filter=ReportsCatalogTest` → confirm multiple failures citing `chat_channel_type_pkey` at `CatalogSeeder.php:402`. This is the baseline.

**Verify**: failure observed.

### Step 1: Make catalog seeds idempotent with `upsert` (no SELECT → immune to stale cache AND soft-deletes)

Replace the `foreach { Model::updateOrCreate(['id'=>X], $row) }` pattern with a
single `Model::upsert(...)` per method. `upsert` emits
`INSERT ... ON CONFLICT (id) DO UPDATE SET ...` — no lookup SELECT, so the stale
model-cache result cannot misroute it to an INSERT.

Add a private helper and use it in every `seedXxx()`:
```php
/**
 * Idempotent catalog seed: INSERT ... ON CONFLICT (id) DO UPDATE.
 * No SELECT, so it is immune to the Cachable stale-lookup that made the
 * previous foreach+updateOrCreate throw a duplicate PK on re-seed (plan 040).
 * Resets deleted_at so a soft-deleted catalog row is restored; never overwrites
 * created_at on conflict.
 *
 * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
 * @param  array<int, array<string, mixed>>  $rows
 */
private function upsertCatalog(string $model, array $rows): void
{
    if ($rows === []) {
        return;
    }
    $rows = array_map(static fn (array $r): array => $r + ['deleted_at' => null], $rows);
    $updateColumns = array_values(array_diff(array_keys($rows[0]), ['id', 'created_at']));
    $model::upsert($rows, ['id'], $updateColumns);
}
```
Then each method becomes e.g.:
```php
private function seedChatChannelTypes(): void
{
    $this->upsertCatalog(ChatChannelType::class, [
        ['id' => ChatChannelType::OFFICIAL, 'name' => 'Oficial', 'order' => 0, 'is_active' => true],
        // ...the same rows...
    ]);
}
```
Apply to ALL `seedXxx()` methods that currently do `foreach { updateOrCreate(['id'=>...]) }`.

Notes the executor must respect:
- `updateColumns` excludes `id` (the conflict key) and `created_at` (so an existing
  row keeps its original created_at; new rows get it from the `useCurrent()` DB default).
- `deleted_at => null` is added to every row and IS in the update columns, so a
  previously soft-deleted catalog row is restored on conflict.
- `upsert` bypasses model events / `HasUuids` (ids are explicit constants — fine)
  and `Cachable` write-side invalidation (acceptable for seeding; see Step 3).

**Verify**: `php -l database/seeders/CatalogSeeder.php` → no errors;
`grep -n "updateOrCreate(\['id'" database/seeders/CatalogSeeder.php` → zero matches.

### Step 2: Confirm the target test is green

`php artisan test --filter=ReportsCatalogTest` → all methods pass (no `chat_channel_type_pkey`).

**Verify**: green.

### Step 2b: Apply the same fix to TenantCatalogSeeder if it shares the pattern

Locate the per-tenant catalog seeder(s) (`grep -rln "updateOrCreate(\['id'" backend/Modules --include=*Seeder*.php` and the `database/seeders`). If any seed `TenantCatalogModel`/`CentralCatalogModel` rows with the same `foreach updateOrCreate(['id'=>...])` shape, apply the same `upsert` conversion. If their pattern differs, leave them and note it in your report.

**Verify**: `grep -rn "updateOrCreate(\['id'" backend/database/seeders backend/Modules --include=*Seeder*.php` → zero (or only patterns you justified leaving).

### Step 3: Belt-and-suspenders — disable model caching in tests

Model caching should never be active in tests (the config file's own comment warns
non-taggable stores cause "false greens"). Add to `backend/phpunit.xml`'s `<php>`
env block:
```xml
<env name="MODEL_CACHE_ENABLED" value="false"/>
```
This guarantees the `Cachable` stale-lookup class of bug cannot recur in any test,
independent of the seeder change.

**Verify**: `grep -n "MODEL_CACHE_ENABLED" backend/phpunit.xml` → present, value false.

### Step 4: Broader green + format

- `cd backend && php artisan test --filter=Reports` → no `chat_channel_type_pkey`; failures (if any) are unrelated and pre-existing — list them.
- `cd backend && php artisan test --filter=Collections` → same.
- `cd backend && vendor/bin/pint database/seeders/CatalogSeeder.php` → exit 0.

## Test plan

- No new test file is strictly required — the FIX's proof is that the
  previously-red broad filters now run without the seeder collision.
- Optionally add `backend/Modules/Collections/tests/Unit/Seeders/` (a sibling
  `CatalogSeederPeriodicityTest.php` already exists there — model after it) a test
  `test_catalog_seeder_is_rerun_safe`: call `(new CatalogSeeder)->run()` twice in
  one test and assert no exception + stable row counts.

## Done criteria

ALL must hold:
- [ ] `grep -n "updateOrCreate(\['id'" backend/database/seeders/CatalogSeeder.php` → zero
- [ ] `cd backend && php artisan test --filter=ReportsCatalogTest` → all pass
- [ ] `cd backend && php artisan test --filter=Reports` and `--filter=Collections` → no `chat_channel_type_pkey` duplicate-key failures
- [ ] `backend/phpunit.xml` sets `MODEL_CACHE_ENABLED=false`
- [ ] `vendor/bin/pint database/seeders/CatalogSeeder.php` exit 0
- [ ] Only in-scope files modified (`git -C backend status`)
- [ ] `plans/README.md` row updated

## STOP conditions

- Step 0 does NOT reproduce the failure (the env may already differ) — report; do not invent a fix for an absent bug.
- After Step 1, `ReportsCatalogTest` still fails with `chat_channel_type_pkey` — the
  cache hypothesis is incomplete; capture the failing query (enable `DB::listen`
  in a throwaway you delete) and report the actual SELECT/INSERT, rather than guessing.
- `upsert` causes a NOT-NULL violation (a catalog table has a NOT-NULL own column
  not present in the row arrays) — report which column/table.
- A previously-green test goes red after the change (e.g. one that relied on
  `updateOrCreate` firing model events) — report which and why.

## Maintenance notes

- New central/tenant catalog seeds must use `upsertCatalog(...)`, never
  `foreach { updateOrCreate(['id'=>...]) }` on a `Cachable` model.
- The advisor DISPROVED the initial "SoftDeletes hides the row" theory empirically
  (double-seed throws with zero soft-deletes) — the cause is the `Cachable`
  stale lookup. `upsert` fixes both the cache and the (theoretical) soft-delete variant.
- If model caching is ever enabled in a test env deliberately, this fix still holds
  (upsert has no cached SELECT).
