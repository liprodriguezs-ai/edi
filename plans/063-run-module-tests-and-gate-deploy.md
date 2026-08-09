# Plan 063: Run the module-local test suites and gate deploy on the test workflow

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- phpunit.xml .github/workflows/test.yml .github/workflows/main.yml`

## Status

- **Priority**: P1
- **Effort**: S–M (the phpunit edit is S; triaging whatever turns red is the M part)
- **Risk**: MED (previously-unrun tests may be red against current schema/code)
- **Depends on**: none (but this is the safety net that makes 049–062 verifiable in CI)
- **Category**: tests / DX / CI
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

`phpunit.xml` registers ONLY the root `tests/Unit` and `tests/Feature`. Every module-local
suite under `Modules/*/tests` is therefore invisible to `php artisan test` and to CI —
including **100% of DataImport's tests** (10 files / ~3,700 lines, one docblocked
"SCENARIO-05 (HIGHEST RISK)" cross-tenant isolation test for the chunked import engine),
plus 11 extra Collections files and a Dashboard onboarding test. These tests exist and look
rigorous, so they create FALSE confidence: a regression in DataImport's tenant-scoping
would pass CI silently. Separately, the deploy workflow (`main.yml`) and the test workflow
(`test.yml`) trigger independently on push to the same branches with no `needs:`/
`workflow_run` link — so a red or incomplete suite does not block a production deploy.

## Current state

- `backend/phpunit.xml` (lines 7–14):
  ```xml
  <testsuites>
      <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
      <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
  </testsuites>
  ```
  (env block sets `BILLING_ENFORCE_SUBSCRIPTION=false`, `MODEL_CACHE_ENABLED=false`, etc.)
- `backend/.github/workflows/test.yml`: `on: push [main,staging,development] + pull_request`;
  job runs `php artisan test --parallel` against a Postgres 16 service container.
- `backend/.github/workflows/main.yml`: `on: push [main,staging,development]`; deploys via
  `uses: Extendevs/ci-core/.github/workflows/api-template.yml@main`. NO link to test.yml.
- Module tests live at e.g. `Modules/DataImport/tests/{Feature,Unit}`,
  `Modules/Collections/tests/*`, `Modules/Dashboard/tests/*`, `Modules/Package/tests/*`,
  `Modules/Chat/tests/*`, `Modules/Access/tests` (some `.gitkeep`-only).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| List suites | `cd backend && php artisan test --list-tests \| head` | after edit: module tests appear |
| Run a module | `cd backend && php artisan test Modules/DataImport` | runs DataImport's suite |
| Full run | `cd backend && php artisan test` | see what's red (triage) |

> Host php, shared `testing` DB, ONE process at a time locally. CI uses `--parallel`
> (paratest assigns separate DBs) — that's fine in CI, just not for two LOCAL runs at once.

## Scope

**In scope**:
- `backend/phpunit.xml` — add `Modules/*/tests/Feature` and `Modules/*/tests/Unit` to the
  testsuites (glob include).
- `backend/.github/workflows/main.yml` — gate the deploy on `test.yml` success (via
  `workflow_run` or by merging into a single sequential workflow with `needs:`).
- Triage: fix or explicitly mark (skip with a tracked reason) any module test that is red
  purely due to never having run — do NOT weaken a test that reveals a real bug.

**Out of scope**:
- Writing NEW tests (that's per-feature; e.g. plans 049–062 add their own).
- The parallel-DB / single-process constraint itself (documented; CI handles it).

## Steps

### Step 1: Register module test suites

Edit `phpunit.xml` testsuites to include module dirs, e.g.:
```xml
<testsuite name="Modules">
    <directory>Modules/*/tests/Unit</directory>
    <directory>Modules/*/tests/Feature</directory>
</testsuite>
```
(Confirm PHPUnit 12 accepts the glob; if not, list the module dirs explicitly.)

**Verify**: `php artisan test --list-tests | grep -i dataimport` shows DataImport tests.

### Step 2: Triage the newly-included tests

`php artisan test` (single local process). For each failure, determine: real bug (leave
red, report — it may deserve its own plan) vs. stale test needing a trivial fixture/schema
fix vs. environment (e.g. needs a seed). Fix stale ones; for anything revealing a real bug,
STOP and report it as a finding rather than editing the test to pass.

**Verify**: the suite is green OR every remaining red is documented as a real-bug finding
(not silenced).

### Step 3: Gate deploy on tests

Change `main.yml` to not deploy unless `test.yml` succeeded. Simplest safe approach:
```yaml
on:
  workflow_run:
    workflows: ["Tests"]
    types: [completed]
    branches: [main, staging, development]
jobs:
  deploy:
    if: ${{ github.event.workflow_run.conclusion == 'success' }}
    uses: Extendevs/ci-core/.github/workflows/api-template.yml@main
    with: { ssh_port: "22" }
    secrets: inherit
```
(Adjust to how `ci-core` expects to be triggered; if `workflow_run` doesn't carry the ref
the reusable workflow needs, instead merge test+deploy into one workflow where `deploy`
`needs: test`.)

**Verify**: on a test push, deploy runs only after Tests is green (confirm in the Actions
tab, or reason through the YAML if you can't push).

### Step 4 (recommended): add `composer audit` to CI

Add a step to `test.yml` running `composer audit` so a future critical advisory (see plan
057) fails the build. Non-blocking if the team prefers `--format` reporting first.

## Done criteria

- [ ] `phpunit.xml` includes `Modules/*/tests`; `php artisan test --list-tests` shows them
- [ ] Full suite green, OR every remaining failure is documented as a real-bug finding
      (never silenced to go green)
- [ ] `main.yml` deploy is gated on `test.yml` success
- [ ] (Recommended) `composer audit` runs in CI
- [ ] `plans/README.md` row updated

## STOP conditions

- A newly-included test reveals a real bug (e.g. DataImport `CrossTenantIsolationTest`
  fails showing an actual leak) — STOP, do NOT weaken it; report it as an urgent finding
  with its own follow-up plan.
- The `--parallel`/paratest binary isn't available in CI after including more tests (the
  repo notes paratest isn't installed locally) — CI's `test.yml` already uses `--parallel`,
  so confirm it works there; if not, drop to serial in CI and note it.
- `ci-core`'s reusable workflow can't be triggered by `workflow_run` — fall back to a
  single workflow with `needs: test` and report the approach.

## Maintenance notes

- New modules must drop tests under `Modules/<Name>/tests/{Unit,Feature}` and they'll be
  picked up automatically by the glob.
- Frontend/landing have NO CI at all (vitest + lint run nowhere) — a follow-up should add a
  frontend CI job running `pnpm lint` + `pnpm test -- --run` + `pnpm audit --prod`. Noted
  in the index; separate plan.
- Reviewer: confirm no test was edited merely to pass — that would re-hide the exact bugs
  this plan exposes.
