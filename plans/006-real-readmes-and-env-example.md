# Plan 006: Replace wrong READMEs and complete `.env.example` files

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- README.md .env.example` and
> `cd ../frontend && git diff --stat 74c0b0e..HEAD -- README.md .env.example`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: backend `803df3a` / frontend `74c0b0e`, 2026-06-11

## Why this matters

`backend/README.md` documents a **Python users-import script from a different
project** ("Users Import Script", `pip install psycopg2-binary`, `python
users_import.py`) — it never mentions Laravel, PHP, or this product (Kivo7 /
Residia). A new developer following it installs Python and hunts for a
nonexistent CSV. The frontend README is the default Vite template. And
`frontend/.env.example` has a single variable while the app needs Firebase,
Stripe and API config. Onboarding is effectively broken. This plan replaces both
READMEs with accurate setup instructions and fills in the `.env.example` files —
**without writing any real secret value** (placeholders only).

## Current state

- `backend/README.md` — top lines:
  ```
  # Users Import Script
  A Python script that replicates the functionality of the PHP `UsersImport` class...
  ```
  Entirely wrong. The real stack (from `.project-context/stack.md` and root
  `CLAUDE.md`): Laravel 13, PHP 8.4, PostgreSQL 16, Redis, Octane, Sanctum,
  nwidart modules, Orion, Stancl single-DB tenancy, seeders for catalogs +
  permissions.
- `frontend/README.md` — default Vite + React template.
- `frontend/.env.example` — single line: `VITE_API_BASE_URL=http://localhost:8000/api`.
  The app also uses Firebase (`src/shared/firebase/firebaseConfig.ts` reads
  `VITE_FIREBASE_*` vars) and a page-size default (`VITE_PAGE_SIZE_DEFAULT`,
  referenced in `.claude/rules/project-overview.md`).
- `backend/.env.example` — exists but may be incomplete; the app needs DB,
  Redis/queue, Sanctum, Stripe (Cashier), Firebase server credentials, and
  tenancy config. Read it first and fill gaps.

**Source of truth for setup facts**: `.project-context/stack.md`,
`.project-context/domain.md`, root `CLAUDE.md` section "3. STRICT GENERAL RULES"
(mentions `php artisan db:seed --class=ResetPermissionTableSeeder`), and
`backend/composer.json` (PHP version constraint, scripts).

## Commands you will need

| Purpose            | Command                                  | Expected                  |
|--------------------|------------------------------------------|---------------------------|
| Read backend env keys | `grep -o '^[A-Z_]*' backend/.env.example \| sort -u` | list of declared vars |
| Find frontend env reads | `grep -rn "import.meta.env" frontend/src/` | every `VITE_*` the app reads |
| Verify setup steps | run them in a scratch checkout if possible | commands succeed |

## Scope

**In scope**:
- `backend/README.md` (rewrite)
- `frontend/README.md` (rewrite)
- `frontend/.env.example` (add the missing `VITE_*` vars as placeholders)
- `backend/.env.example` (add missing vars as placeholders) — ONLY if gaps exist

**Out of scope**:
- Any real `.env` file or any real credential value (placeholders ONLY — e.g.
  `VITE_FIREBASE_API_KEY=your-firebase-api-key`).
- CONTRIBUTING / architecture docs — separate concern.
- The `.project-context/*` files — those are addressed in plan 007.

## Git workflow

- Branch: `advisor/006-real-readmes-and-env-example` (create in BOTH submodules as needed)
- Conventional commit: `docs: replace placeholder READMEs and complete .env.example`
- No push/PR unless instructed. `backend/` and `frontend/` are separate submodules — commit in each.

## Steps

### Step 1: Discover the real env vars the app reads

- Frontend: `grep -rn "import.meta.env" frontend/src/` → collect every `VITE_*`
  key actually read in code. At minimum expect `VITE_API_BASE_URL`,
  `VITE_PAGE_SIZE_DEFAULT`, and the `VITE_FIREBASE_*` set used in
  `src/shared/firebase/firebaseConfig.ts`.
- Backend: read `backend/.env.example` and `backend/config/*.php` for the env
  keys referenced (`DB_*`, `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`,
  `STRIPE_*`, `FIREBASE_*`, Sanctum/session vars). Note which are missing from
  `.env.example`.

### Step 2: Rewrite `backend/README.md`

Write accurate setup: project name (Kivo7 / Residia — a multi-tenant SaaS to
administer residential clusters in Mexico), stack (Laravel 13, PHP 8.4,
PostgreSQL, Redis, Octane), and the real steps:
```
composer install
cp .env.example .env
php artisan key:generate
# configure DB / Redis / Stripe / Firebase in .env
php artisan migrate
php artisan db:seed                 # base catalogs + permissions
php artisan serve                   # API on :8000
```
Mention that modules live in `Modules/` (nwidart) and tenancy is single-DB
row-level (no per-tenant database). Keep it concise and correct — do not invent
commands; every command must be one you verified exists (`php artisan list`
shows available commands).

### Step 3: Rewrite `frontend/README.md`

Accurate setup: React 19 + Vite + TypeScript + Tailwind, pnpm:
```
pnpm install
cp .env.example .env       # set VITE_API_BASE_URL and Firebase vars
pnpm dev                   # dev server
pnpm build                 # production build
```

### Step 4: Complete the `.env.example` files

Add every var discovered in Step 1 that's missing, with a **placeholder** value
and a short inline comment where non-obvious. Never a real value.

**Verify**:
- `grep -c VITE_FIREBASE frontend/.env.example` → ≥1.
- `grep -c VITE_API_BASE_URL frontend/.env.example` → 1.
- Backend `.env.example` contains the keys the app's config reads (spot-check
  `STRIPE`, `FIREBASE`, `DB_CONNECTION`).
- No real secret appears: `git diff` shows only placeholder strings.

## Test plan

No automated test. Verification is: a fresh reader can follow each README's
commands and reach a running app. If a scratch checkout is feasible, dry-run the
frontend steps (`pnpm install` then `pnpm build`) to confirm they're accurate.

## Done criteria

ALL must hold:

- [ ] `backend/README.md` contains no Python/`pip`/`users_import` references and documents the Laravel setup.
- [ ] `frontend/README.md` documents the real pnpm/Vite setup (not the Vite template).
- [ ] `frontend/.env.example` includes the Firebase `VITE_*` vars and `VITE_PAGE_SIZE_DEFAULT`.
- [ ] No real credential value in any committed file (placeholders only).
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- A README at either path no longer matches "Current state" (someone already
  rewrote it — drift).
- You cannot determine a required env var's purpose from code/config — leave it
  with a `# TODO: document` placeholder and note it in your report rather than
  guessing.
- Any setup command you'd document fails when you try it — report the failure;
  do not document a command you couldn't run.

## Maintenance notes

- Keep `.env.example` in lockstep with new `import.meta.env` / `config(env())`
  reads — a CI check that greps for undocumented env vars would prevent drift
  (possible follow-up).
- A reviewer must scan the diff for any accidentally pasted real secret before
  approving.
