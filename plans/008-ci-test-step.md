# Plan 008: Add a test gate to CI before deploy

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- .github/`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: MED
- **Depends on**: none (but pairs with 009 to add frontend tests to the gate later)
- **Category**: dx
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

The only CI workflow (`backend/.github/workflows/main.yml`) **deploys** — it runs
no tests. The backend has ~590 passing PHPUnit tests that are executed only
manually on developers' machines, so a PR that breaks them can reach `main` and
deploy. This plan adds a test job that must pass before the deploy job runs,
turning the existing suite into an actual safety net.

## Current state

- `backend/.github/workflows/main.yml` — a single workflow ("Deploy React to
  cPanel") that calls a reusable external workflow
  (`Extendevs/ci-core/.github/workflows/api-template.yml@main`) with SSH deploy
  params. No `php artisan test` / `phpunit` step anywhere. It is the only `.yml`
  under `.github/`.
- Test command (verified): `php artisan test` (PHPUnit). The suite uses
  PostgreSQL; many tests use `RefreshDatabase` and tenant seeding. A parallel run
  is `php artisan test --parallel`.
- The app needs: PHP 8.4, PostgreSQL, and the standard Laravel test bootstrap
  (`.env.testing` or env vars; `php artisan key:generate`).

## Commands you will need

| Purpose            | Command (from `backend/`)        | Expected            |
|--------------------|----------------------------------|---------------------|
| Run suite locally  | `php artisan test`               | all pass            |
| Run in parallel    | `php artisan test --parallel`    | all pass            |
| Validate workflow YAML | `yamllint .github/workflows/*.yml` (if available) or careful manual read | valid |

## Scope

**In scope**:
- `backend/.github/workflows/` — add a new workflow file OR add a `test` job to
  the existing one (see Step 1 for the decision).

**Out of scope**:
- The external reusable workflow `Extendevs/ci-core` (you cannot edit it; you can
  only call it or gate it).
- Frontend CI (the frontend has no tests yet — plan 009 adds them; once green,
  a follow-up adds them here).
- Any change to deploy credentials/secrets.

## Git workflow

- Branch: `advisor/008-ci-test-step`
- Conventional commit: `ci: run backend test suite before deploy`
- No push/PR unless instructed. `backend/` is a submodule — but note CI runs on
  whatever remote the submodule pushes to; confirm with the operator before
  enabling on a shared branch.

## Steps

### Step 1: Decide where the test job lives

Read `main.yml`. Two options:
- If the deploy is triggered by this workflow directly, add a `test` job and make
  the deploy job `needs: test`.
- If the deploy is entirely delegated to the external reusable workflow and you
  cannot insert a `needs:` before it, create a SEPARATE workflow file
  `backend/.github/workflows/test.yml` that runs on `pull_request` and `push`,
  so tests at least run — and document (in the PR) that branch protection should
  require the `test` check before merge.

Pick the option that the current `main.yml` structure actually permits. If
unsure which, prefer the separate `test.yml` (lower risk, doesn't touch deploy).

### Step 2: Write the test job

A minimal, correct PostgreSQL + PHP 8.4 job:
```yaml
name: Tests
on:
  pull_request:
  push:
    branches: [main]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: testing
        ports: ['5432:5432']
        options: >-
          --health-cmd pg_isready --health-interval 10s
          --health-timeout 5s --health-retries 5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo_pgsql, redis, bcmath, gd
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan test --parallel
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: testing
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
          CACHE_STORE: array
          QUEUE_CONNECTION: sync
          SESSION_DRIVER: array
```
Adjust the PHP `extensions` list to match what `composer.json` requires (read its
`require` for `ext-*`). Adjust env vars to match what the test bootstrap expects
(read `phpunit.xml` for any `<env>` entries — match them; do not contradict them).

### Step 3: Validate the YAML

Manually re-read for indentation/structure. If `yamllint` is available, run it.

## Test plan

- This *is* the test infrastructure. The verification is that the job, when run
  (locally simulated or on a throwaway branch the operator approves), executes
  `php artisan test` against a real Postgres and reports pass/fail.
- Before relying on it, confirm `php artisan test --parallel` passes locally so a
  CI failure means a real regression, not an environment gap.

## Done criteria

ALL must hold:

- [ ] A workflow exists that runs `php artisan test` (parallel or not) on PRs.
- [ ] It provisions PostgreSQL 16 and PHP 8.4 with the extensions `composer.json` requires.
- [ ] Env vars in the job match `phpunit.xml`'s expectations (no contradictions).
- [ ] If using `needs:`, deploy depends on `test`; if separate, the PR notes that branch protection should require the check.
- [ ] `php artisan test --parallel` passes locally (so the gate reflects real state).
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- `main.yml` no longer matches "Current state" (drift).
- The test suite does NOT pass locally on the executor's machine — STOP; CI must
  not be wired to a red baseline. Report the failing tests.
- `phpunit.xml` requires services beyond Postgres (e.g. a real Redis or
  Meilisearch/Scout) that the job doesn't provide — add the matching `services:`
  or set the driver to a test-safe value (`CACHE_STORE=array`, `SCOUT_DRIVER=null`),
  and report what you changed.

## Maintenance notes

- Once plan 009 lands (frontend vitest), add a parallel `frontend-test` job
  (`pnpm install && pnpm test`) and gate deploy on both.
- Keep the CI PHP version pinned to the same `8.4` the app targets; bump together.
- A reviewer should confirm the job actually fails when a test fails (not a
  false green from a misconfigured DB that skips `RefreshDatabase`).
