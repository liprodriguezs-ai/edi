# Plan 068: Make the backend CI test job actually run, then gate the deploy on it

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 46f8a07..HEAD -- .github/workflows composer.json`
> If any of those files changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED
- **Depends on**: none — this is a prerequisite for trusting every other plan's verification
- **Category**: dx / tests
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

The backend has 250 test files, including every cross-tenant isolation test and
the whole Billing and Collections suites. **None of them has ever executed in
CI.** The workflow runs `php artisan test --parallel`, but the package that
`--parallel` requires (`brianium/paratest`) is not installed, so Laravel's test
command throws before running a single test. Separately, the deploy workflow is
an independent workflow triggered by the same push with no dependency on the
test job — so even a red test run would not stop a deploy.

The result is that the repository has the *appearance* of CI coverage with none
of the substance. Every other plan in this cycle is verified by tests; until
this lands, "the tests pass" means "the tests passed on someone's laptop".

Order matters here and it is the whole point of this plan: **fix the runner
first, gate second.** Adding the gate to a job that cannot pass would block
every deploy to production.

## Current state

- `backend/.github/workflows/test.yml` — the test workflow. Sets up PHP 8.4 and a
  Postgres 16 service, then at line 60:

  ```yaml
  # backend/.github/workflows/test.yml:59-70
        - name: Run test suite
          run: php artisan test --parallel
          env:
            APP_ENV: testing
            DB_CONNECTION: pgsql
            DB_HOST: 127.0.0.1
            DB_PORT: 5432
            DB_DATABASE: testing
            DB_USERNAME: postgres
            DB_PASSWORD: postgres
  ```

- `backend/composer.json` — `require-dev` contains exactly these 8 packages, and
  `brianium/paratest` is **not** among them:

  ```
  fakerphp/faker, laravel/boost, laravel/pail, laravel/pint,
  laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit
  ```

  `ls backend/vendor/bin` confirms the absence: `carbon, google-cloud-batch,
  jp.php, patch-type-declarations, php-parse, phpunit, pint, psysh,
  roadrunner-worker, sail, swoole-server, var-dump-server, yaml-lint` — no
  `paratest`. `backend/vendor/brianium/` does not exist.

- The guard that throws:

  ```php
  // backend/vendor/nunomaduro/collision/src/Adapters/Laravel/Commands/TestCommand.php:88-93
          /** @var bool $usesParallel */
          $usesParallel = $this->option('parallel');

          if ($usesParallel && ! $this->isParallelDependenciesInstalled()) {
              throw new RequirementsException('Running Collision 8.x artisan test command in parallel requires at least ParaTest (brianium/paratest) 7.x.');
          }
  ```

- `backend/.github/workflows/main.yml` — the deploy workflow, in full. Note there
  is no `needs:` and no reference to the test job:

  ```yaml
  # backend/.github/workflows/main.yml
  name: Deploy React to cPanel

  on:
    push:
      branches: ["main", "staging", "development"]
    workflow_dispatch:

  jobs:
    deploy:
      uses: Extendevs/ci-core/.github/workflows/api-template.yml@main
      with:
        ssh_port: "22"
      secrets: inherit
  ```

- `backend/phpunit.xml:8-19` defines three suites — `tests/Unit`,
  `tests/Feature`, and `Modules/*/tests/{Unit,Feature}`. All three are in scope
  for the CI run.

### Repo conventions that apply

- Conventional commits, in Spanish or English, **never** with `Co-Authored-By`
  or any AI attribution. Example from `git log`:
  `feat(notifications): enhance Nightwatch integration and document notification observability`
- `backend/` is a git submodule. Paths in this plan are written from the monorepo
  root, but the commit lands inside `backend/`.

### The operational fact that drives the risk rating

`/Users/rodriguez/code/residia/AGENTS.md` documents that only **one** test
process may run at a time locally, because `RefreshDatabase` does a
`migrate:fresh` against a shared `testing` database. It also records a known
**non-idempotent catalog-seeder** problem: running many test directories in a
single process triggers duplicate-key failures in `CatalogSeeder` /
`TenantCatalogSeeder` that are *not* real regressions. Plan 040 partially fixed
this with an `upsertCatalog()` helper.

This matters because paratest gives each worker its own database
(`testing_1`, `testing_2`, …), which changes the failure surface. Serial
execution is therefore the safer first move.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Lint YAML by eye | `cat backend/.github/workflows/test.yml` | matches the excerpt above |
| Confirm paratest absence | `ls backend/vendor/brianium 2>&1` | "No such file or directory" |
| Run the suite serially, locally | `cd backend && php artisan test` | see STOP conditions about pre-existing failures |
| Run one area only | `cd backend && php artisan test --filter=Collections` | all pass |
| Check no other test process is running | `pgrep -f "artisan test"` | no output |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope** (the only files you should modify):
- `backend/.github/workflows/test.yml`
- `backend/.github/workflows/main.yml`

**Out of scope** (do NOT touch, even though they look related):
- `backend/composer.json` / `composer.lock` — see Step 1; adding paratest is the
  *alternative* you are explicitly NOT taking in this plan. Do not add the
  dependency.
- `backend/phpunit.xml` — the testsuite definitions are correct as-is.
- `backend/database/seeders/CatalogSeeder.php` — the seeder idempotency work is
  plan 040's territory and is already partially landed. If CI goes red because of
  it, that is a STOP condition, not something to fix here.
- Any test file. If a test fails, you report it; you do not edit it to pass.

## Git workflow

- Branch: `advisor/068-ci-test-runner`
- One commit per step is fine; suggested messages:
  - `ci(tests): run the backend suite serially so the job can actually execute`
  - `ci(deploy): gate the cPanel deploy on the test job`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Make the test job run serially

In `backend/.github/workflows/test.yml`, change the run step from
`php artisan test --parallel` to `php artisan test`.

Leave every other line of that step — including the whole `env:` block —
untouched.

Rationale to preserve as a comment directly above the `run:` line:

```yaml
      - name: Run test suite
        # Serial on purpose: --parallel requires brianium/paratest, which is not
        # a dependency of this project. Paratest also gives each worker its own
        # database (testing_1, testing_2, …), which interacts badly with the
        # known non-idempotent catalog seeders. Revisit only after those are
        # idempotent.
        run: php artisan test
```

**Verify**: `grep -n "artisan test" backend/.github/workflows/test.yml`
→ exactly one match, reading `run: php artisan test`, with no `--parallel`.

### Step 2: Prove the suite can pass locally before you gate anything

Before touching the deploy workflow, establish the baseline. First confirm no
other test process is running (a zombie run corrupts the shared database and
produces fake schema errors):

```bash
pgrep -f "artisan test"   # must print nothing
```

Then run the suite serially:

```bash
cd backend && php artisan test
```

Record the exact pass/fail counts in your report. **Do not fix any failing
test.** Read the STOP conditions below before deciding what a failure means.

**Verify**: you have a recorded count of passed / failed / skipped tests, and
you have classified every failure as either (a) pre-existing and unrelated to
this plan, or (b) a STOP condition.

### Step 3: Gate the deploy on the test job

Only do this step if Step 2's suite was green, or if every failure was
confirmed pre-existing **and** the operator explicitly accepted gating anyway.

The two workflows are currently independent files, so `needs:` cannot reach
across them. Convert the deploy workflow to depend on the test workflow using
`workflow_run`:

```yaml
# backend/.github/workflows/main.yml
name: Deploy React to cPanel

on:
  workflow_run:
    workflows: ["Tests"]
    types: [completed]
    branches: ["main", "staging", "development"]
  workflow_dispatch:

jobs:
  deploy:
    # Deploy only when the Tests workflow actually succeeded. workflow_dispatch
    # keeps a manual escape hatch for emergencies.
    if: >-
      github.event_name == 'workflow_dispatch' ||
      github.event.workflow_run.conclusion == 'success'
    uses: Extendevs/ci-core/.github/workflows/api-template.yml@main
    with:
      ssh_port: "22"
    secrets: inherit
```

Two things to be careful about:
- `workflows: ["Tests"]` must match the `name:` of `test.yml` exactly. It is
  currently `Tests` (line 1 of that file). Verify before committing.
- The `if:` guard is required. Without it, `workflow_run` fires on *completed*
  regardless of conclusion, which would deploy on red.

**Verify**: `grep -n "workflow_run\|conclusion\|workflows:" backend/.github/workflows/main.yml`
→ shows the `workflow_run` trigger, the `workflows: ["Tests"]` list, and the
`conclusion == 'success'` guard.

And: `grep -n "^name:" backend/.github/workflows/test.yml` → `name: Tests`
(this is the string the deploy workflow keys on).

## Test plan

This plan changes CI configuration only; it adds no application code and
therefore no new unit tests.

The verification is the CI run itself, which cannot be confirmed from a local
checkout. State plainly in your report that:
- the local serial run's result (Step 2) is the evidence the suite can pass, and
- the CI behavior is **unverified until someone pushes**, because the deploy
  gate only exercises on a real `workflow_run` event.

Do not claim the gate works. Report it as "configured, pending first push".

## Done criteria

ALL must hold:

- [ ] `grep -c "\-\-parallel" backend/.github/workflows/test.yml` returns `0`
- [ ] `grep -c "workflow_run" backend/.github/workflows/main.yml` returns `1`
- [ ] `grep -c "conclusion == 'success'" backend/.github/workflows/main.yml` returns `1`
- [ ] The `workflows:` value in `main.yml` string-matches the `name:` in `test.yml`
- [ ] `git -C backend status --porcelain` shows only the two workflow files modified
- [ ] Step 2's local suite result is recorded in the report with exact counts
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **The local serial suite fails with catalog/seeder duplicate-key errors**
  (`SQLSTATE[23505]`, `duplicate key`, `chat_channel_type_pkey`, or similar in a
  `*Seeder`). That is the known non-idempotent-seeder problem, it is plan 040's
  territory, and it means the deploy gate would flap. Do NOT gate. Report the
  failure and stop after Step 1.
- **The local suite fails with `SQLSTATE 42P01` / `42703` (`relation ... does not
  exist`)**. Per `AGENTS.md` this is almost always a second test process
  colliding on the shared database, not a code failure. Run `pgrep -f "artisan
  test"`, kill any stragglers, and re-run once. If it persists after a clean
  re-run, stop and report.
- **More than a handful of tests fail for reasons unrelated to seeders.** Gating
  a red suite blocks all deploys. Report the failures and stop after Step 1 —
  Step 1 alone is still a net improvement (the job goes from "always errors" to
  "reports the truth").
- **`test.yml`'s `name:` is not `Tests`.** The `workflow_run` trigger keys on
  that exact string; guessing it wrong silently disables the gate. Stop and
  report the actual name.
- **You conclude paratest should be installed after all.** That is a different
  change with different risk (per-worker databases vs. the known seeder
  problem). It is explicitly out of scope. Report the reasoning and stop.

## Maintenance notes

- **If the seeders are made fully idempotent later** (finishing plan 040's
  `upsertCatalog()` work), revisit `--parallel`: adding
  `composer require --dev brianium/paratest` would cut the ~23-minute serial run
  substantially. The comment added in Step 1 is the breadcrumb for that.
- **A reviewer should scrutinize** the `if:` guard on the deploy job above all
  else. A `workflow_run` trigger without a conclusion check is strictly worse
  than no gate: it looks gated and is not.
- **Deliberately deferred**: there is still no CI for `frontend/` or `landing/`
  at all — 93 vitest files and eslint run only in a local husky pre-commit hook,
  and the frontend deploys via Cloudflare Workers Builds on every push with no
  gate whatsoever. A frontend CI job would go green immediately (`pnpm
  typecheck` is clean, `pnpm lint` has 0 errors) and is the natural follow-up,
  but it is a separate workflow file in a separate submodule and is not in this
  plan's scope.
- **Known unrelated cruft** spotted nearby: `backend/.claude/worktrees/` holds a
  stale worktree with a full `vendor/` tree, which makes naive `find` sweeps
  report ~520 backend test files instead of the real 250. Do not let it confuse
  a future count. Not this plan's job to remove.
