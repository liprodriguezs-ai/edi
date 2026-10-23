# Plan 001: Activate CI (workflows are in the wrong directory) and add a one-command verification loop

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat dbe3682..HEAD -- backend/.github frontend/package.json`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (the first real CI run may be red for infrastructure reasons — see "Why this matters")
- **Depends on**: none
- **Category**: dx
- **Planned at**: commit `dbe3682`, 2026-08-25

## Why this matters

The repo has a complete, correct GitHub Actions test pipeline — and it has **never run**, because it lives at `backend/.github/workflows/` while the git repository root is `/Users/rodriguez/code/edi-app`. GitHub Actions only discovers workflows at `<repo-root>/.github/workflows/`. Meanwhile 218 uncommitted files containing the entire clinical core (Screening, Instrument, FollowUp, Stimulation, Reports) are about to land on `main` with zero automated verification, and the documented local test command is a four-environment-variable incantation with a hardcoded personal username. This plan gives the repo a working CI and a `make`-based local verification loop. Every other plan in `plans/` uses these commands as its verification gates, which is why this plan runs first.

## Current state

- `backend/.github/workflows/test.yml` — complete backend pipeline (Postgres 16 service on port 5432, PHP 8.4 with `pdo_pgsql`, `uuid-ossp` extension, `composer install`, `php artisan test --parallel`). Inert: wrong directory.
- `backend/.github/workflows/main.yml` — a cPanel deploy workflow. Also inert. This plan MOVES it but does not enable/edit its logic (it needs secrets that only the operator can configure).
- Repo root has **no** `.github/` directory (verified 2026-08-25).
- `frontend/package.json:35` — `"type-check": "tsc -p --project tsconfig.app.json --noEmit --strict"` is malformed (`-p` consumes `--project` as its argument). The working duplicate is line 41: `"typecheck": "tsc -p tsconfig.app.json --noEmit"`.
- There is no `Makefile` anywhere in the repo.
- AGENTS.md §5 documents the local backend test invocation:

```bash
DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password \
  php artisan test --parallel --processes=10
```

  and warns (documented, measured): under `--parallel` roughly 1 in 3 full runs fails with 1–18 connection/assertion errors due to Postgres contention (Docker has 6 CPUs; `InstrumentSeeder` costs ~12.9 s per process). **In serial the suite passes 100%.**
- Frontend verification commands (AGENTS.md §5): `pnpm exec tsc -p tsconfig.app.json --noEmit`, `pnpm exec vitest run`, `pnpm build`.
- Relevant excerpt of `backend/.github/workflows/test.yml` (lines 43–52):

```yaml
      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Run test suite
        run: php artisan test --parallel
```

  All `run:` steps assume the working directory is the Laravel app root. After the move, `actions/checkout` lands at the monorepo root, so these need `working-directory: backend`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| YAML sanity | `python3 -c "import yaml,sys; yaml.safe_load(open(sys.argv[1]))" .github/workflows/test.yml` | exit 0, no output |
| Frontend typecheck | `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit` | exit 0 |
| Frontend tests | `cd frontend && pnpm exec vitest run` | all pass |
| Backend serial tests (local) | `make test-back-serial` (after Step 4) | suite passes |

Local backend tests require the dev Docker stack running (`./start.sh`); if Postgres on port 5451 is not up, skip the local backend run and note it in your report — the YAML checks and frontend runs are still mandatory.

## Scope

**In scope** (the only files you should modify/create/move):
- `backend/.github/` → moved to `.github/` (both workflow files)
- `.github/workflows/test.yml` (edit after the move)
- `Makefile` (create, repo root)
- `frontend/package.json` (delete one script line)
- `plans/README.md` (status row)

**Out of scope** (do NOT touch):
- `.github/workflows/main.yml` beyond the move itself — it references cPanel secrets the operator must own. Move it verbatim.
- `phpunit.xml`, `backend/compose.yaml`, `start.sh` — the local DB wiring is documented and intentional.
- Any source file under `backend/Modules/`, `backend/app/`, `frontend/src/`.
- Do not "fix" the `--parallel` flakiness by editing tests or seeders — that is a known, separately documented issue.

## Git workflow

The working tree currently holds ~218 uncommitted files of in-flight work that is NOT yours. Therefore:
- Do NOT run `git add -A`, `git commit`, or `git stash` at any point.
- Use `git mv` only for the `.github` move (it stages just those paths — that is acceptable; leave them staged).
- Do not push. Do not create branches (branching would carry the uncommitted work).

## Steps

### Step 1: Move the workflows to the repo root

```bash
cd /Users/rodriguez/code/edi-app
git mv backend/.github .github
```

If `git mv` fails because the path is untracked, use plain `mv backend/.github .github`.

**Verify**: `ls .github/workflows/` → `main.yml  test.yml`; `ls backend/.github 2>&1` → "No such file or directory".

### Step 2: Point test.yml at the backend subdirectory and make the run serial

Edit `.github/workflows/test.yml`:

1. Under `jobs.test`, add (sibling of `runs-on`):

```yaml
    defaults:
      run:
        working-directory: backend
```

2. Change the test step from `php artisan test --parallel` to `php artisan test --compact`. Rationale (leave a YAML comment saying this): AGENTS.md §5 documents ~1-in-3 flaky parallel runs from DB contention; serial passes 100%. A slow green pipeline beats a fast flaky one; parallelism can be revisited on CI hardware later.

The `psql` step (`Enable uuid-ossp extension`) talks to the service container over TCP and is directory-independent — the new default working-directory is harmless to it. `cp .env.example .env` now correctly resolves to `backend/.env.example`.

**Verify**: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/test.yml'))"` → exit 0. `grep -n "working-directory: backend" .github/workflows/test.yml` → one match. `grep -c "parallel" .github/workflows/test.yml` → `0` (or only inside a comment).

### Step 3: Add a frontend job to test.yml

Append a second job (top-level under `jobs:`), no dependency on the backend job so they run concurrently:

```yaml
  frontend:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: frontend
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with:
          package_manager: pnpm
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
          cache-dependency-path: frontend/pnpm-lock.yaml
      - run: pnpm install --frozen-lockfile
      - run: pnpm exec tsc -p tsconfig.app.json --noEmit
      - run: pnpm exec vitest run
      - run: pnpm build
```

Note: `pnpm/action-setup@v4` reads the pnpm version from `frontend/package.json`'s `packageManager` field if present; if the field is absent, add `version: 10` to the action's `with:` block instead of guessing.

**Verify**: YAML sanity command → exit 0. `grep -c "runs-on" .github/workflows/test.yml` → `2`.

### Step 4: Create the root Makefile

Create `/Users/rodriguez/code/edi-app/Makefile` with exactly these targets (tab-indented recipes):

```makefile
# Local verification loop. Backend tests need the dev stack up (./start.sh)
# and hit the tmpfs test Postgres on 5451 (see AGENTS.md §5).
DB_HOST ?= 127.0.0.1
DB_PORT ?= 5451
DB_USERNAME ?= $(USER)
DB_PASSWORD ?= password

BACK_ENV = DB_HOST=$(DB_HOST) DB_PORT=$(DB_PORT) DB_USERNAME=$(DB_USERNAME) DB_PASSWORD=$(DB_PASSWORD)

.PHONY: test-back test-back-serial test-front verify

test-back:
	cd backend && $(BACK_ENV) php artisan test --parallel --processes=6

test-back-serial:
	cd backend && $(BACK_ENV) php artisan test --compact

test-front:
	cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit && pnpm exec vitest run

verify: test-front test-back-serial
```

`DB_USERNAME` defaults to `$(USER)` (resolves to the machine's user, e.g. `rodriguez`) instead of hardcoding a name; all four vars are overridable per invocation.

**Verify**: `make -n test-back-serial` → prints the cd + env + artisan line, exit 0. If the Docker stack is up: `make test-front` → typecheck and vitest pass.

### Step 5: Delete the malformed type-check script

In `frontend/package.json`, delete the line `"type-check": "tsc -p --project tsconfig.app.json --noEmit --strict",` (line ~35). Keep `"typecheck"` (line ~41) untouched.

**Verify**: `grep -c '"type-check"' frontend/package.json` → `0`; `grep -c '"typecheck"' frontend/package.json` → `1`. `python3 -c "import json;json.load(open('frontend/package.json'))"` → exit 0.

## Test plan

This plan adds no product code, so no new unit tests. Verification is:
- Both YAML files parse (Step 2/3 commands).
- `make test-front` passes locally.
- `make test-back-serial` passes locally **if** the dev stack is running; otherwise note the skip.
- The real proof — a green Actions run — happens on the next push, which the operator performs. State this explicitly in your report.

## Done criteria

- [ ] `.github/workflows/test.yml` and `.github/workflows/main.yml` exist at repo root; `backend/.github/` is gone
- [ ] YAML sanity command exits 0 for both files
- [ ] `test.yml` has `working-directory: backend` defaults on the backend job and a `frontend` job
- [ ] Backend CI test step is serial (`--compact`, no `--parallel`)
- [ ] `Makefile` exists at root; `make -n` succeeds for all four targets
- [ ] `frontend/package.json` has `typecheck` and no `type-check`
- [ ] `git status` shows changes ONLY in: `.github/` (moved), `Makefile`, `frontend/package.json` — plus the pre-existing unrelated changes that were already there before you started
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- A `.github/` directory already exists at the repo root (someone moved it first — reconcile, don't overwrite).
- `backend/.github/workflows/test.yml` no longer matches the excerpt in "Current state" (drifted).
- `git mv` AND plain `mv` both fail.
- The frontend has no `pnpm-lock.yaml` (the CI cache config would be wrong — report instead of guessing).
- You find yourself wanting to edit any test, seeder, or `phpunit.xml` to make something pass.

## Maintenance notes

- The first push after this lands is the real test of the pipeline; if the backend job fails, check infrastructure causes first (extension list, `uuid-ossp`, env) before blaming code — and remember parallel flakiness is documented, serial is the baseline.
- Revisit `--parallel` on CI only with dedicated hardware and after `InstrumentSeeder` is trimmed for tests (AGENTS.md §5 "Arreglo de fondo pendiente").
- Follow-ups deliberately deferred: root husky hooks (currently `frontend/.husky/` never fires because `core.hooksPath` is unset at the repo root — see plans/README.md backlog), wiring `scripts/check-docs-links.mjs` into CI, and enabling `main.yml` deploy secrets (operator-owned).
- Reviewer scrutiny: confirm `main.yml` moved verbatim (diff it), and that no workflow acquired write permissions it didn't have.
