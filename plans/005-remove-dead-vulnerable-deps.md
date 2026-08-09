# Plan 005: Remove dead, vulnerable frontend dependencies (`xlsx`, `recharts`)

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md` when
> done.
>
> **Drift check (run first)**: from the repo root,
> `cd frontend && git diff --stat 74c0b0e..HEAD -- package.json` and
> `grep -rln "xlsx\|recharts" src/`
> If `src/` now imports either package, treat it as a STOP condition (the
> dependency is no longer dead — re-evaluate).

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: migration
- **Planned at**: frontend commit `74c0b0e`, 2026-06-11

## Why this matters

Two production dependencies carry HIGH-severity CVEs **and are not imported
anywhere in `src/`**:
- `xlsx` (`^0.18.5`) — Prototype Pollution (GHSA-4r6h-8v6p-xvw6) and ReDoS
  (GHSA-5pgg-2g8v-p4x9); no fixed version is published on the public npm registry
  (SheetJS moved off npm), so there is no upgrade path — only removal.
- `recharts` (`2.15.4`) — pulls a vulnerable transitive `lodash` (code injection,
  GHSA-r5fr-rjxr-66jc); `grep` finds **zero** `recharts` imports in `src/`.

Removing both deletes two HIGH CVEs from the production dependency tree at zero
behavioral cost. At audit time `grep -rln "from 'xlsx'\|import.*xlsx" src/`
returned no import statements and `grep -rln "recharts" src/` returned nothing.

## Current state

- `frontend/package.json` `dependencies` — contains `"xlsx": "^0.18.5"` and `"recharts": "2.15.4"`.
- `frontend/src/` — at audit time, **no `import` of either package**. The string
  `"xlsx"` appears in ~4 files but only as a plain string identifier passed to
  the backend (not a JS import). You must re-verify this (Step 1) before removing.
- The dashboard renders charts ("Ingresos vs egresos", "Egresos por categoría")
  but does so WITHOUT recharts — verify what it actually uses so you don't break
  it (Step 1).

## Commands you will need

| Purpose             | Command (from `frontend/`)                          | Expected               |
|---------------------|-----------------------------------------------------|------------------------|
| Find xlsx imports   | `grep -rn "from ['\"]xlsx['\"]\|require(['\"]xlsx['\"])\|import .*['\"]xlsx['\"]" src/` | no matches |
| Find recharts usage | `grep -rn "recharts\|Recharts\|BarChart\|LineChart\|PieChart\|AreaChart" src/` | (inspect any matches) |
| Remove deps         | `pnpm remove xlsx recharts`                          | exit 0                 |
| Typecheck           | `pnpm exec tsc -b`                                   | exit 0 (same as before)|
| Build               | `pnpm build`                                         | exit 0                 |
| Audit (prod)        | `pnpm audit --prod`                                  | xlsx/recharts CVEs gone|

## Scope

**In scope**:
- `frontend/package.json` (dependency removal via `pnpm remove`)
- `frontend/pnpm-lock.yaml` (updated automatically by `pnpm remove`)
- The ≤4 files that reference the string `"xlsx"`, ONLY if removing the dep
  surfaces a build/type error there (it should not — they use a string literal)

**Out of scope**:
- Adding any replacement charting/spreadsheet library. If a real need surfaces
  (it should not), that's a separate plan — STOP and report.
- Backend Excel handling (`maatwebsite/excel`) — unrelated, server-side.

## Git workflow

- Branch: `advisor/005-remove-dead-vulnerable-deps`
- Conventional commit: `chore(deps): remove unused vulnerable xlsx and recharts`
- No push/PR unless instructed. `frontend/` is a submodule.

## Steps

### Step 1: Prove both packages are unused in `src/`

Run the two grep commands from the table.
- The xlsx-import grep MUST return no matches. If it returns a real
  `import ... from 'xlsx'`, **STOP** — the package is in use.
- The recharts grep: inspect every match. If any is a real React usage of
  recharts components, **STOP**. If the only matches are comments or unrelated
  identifiers (e.g. a local `BarChart` component not from recharts), proceed.

### Step 2: Remove the dependencies

`pnpm remove xlsx recharts` → exit 0. This updates `package.json` and the lockfile.

### Step 3: Verify the app still typechecks and builds

- `pnpm exec tsc -b` → exit 0 (no NEW errors vs. the pre-change baseline; if the
  project already has pre-existing TS errors elsewhere, the count must not
  increase — capture the baseline first with the same command on the unchanged
  tree if unsure).
- `pnpm build` → exit 0.

### Step 4: Confirm the CVEs are gone

`pnpm audit --prod` → the xlsx (GHSA-4r6h-8v6p-xvw6, GHSA-5pgg-2g8v-p4x9) and
recharts/lodash (GHSA-r5fr-rjxr-66jc) advisories no longer appear. (Other
advisories may remain — those are out of scope for this plan.)

## Test plan

- No unit test (dependency removal). The build + typecheck + a manual smoke of
  the dashboard page (charts still render) is the verification.
- Manual: run `pnpm dev`, open the dashboard, confirm the two charts still
  render (they don't use recharts, so they should be unaffected).

## Done criteria

ALL must hold (from `frontend/`):

- [ ] `grep -c "xlsx" package.json` → `0`; `grep -c "recharts" package.json` → `0`.
- [ ] `pnpm exec tsc -b` exits 0 with no new errors.
- [ ] `pnpm build` exits 0.
- [ ] `pnpm audit --prod` no longer lists the xlsx or recharts/lodash advisories above.
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- Either package turns out to be imported in `src/` (drift since audit).
- Removing `xlsx` breaks a build because a file did `import` it after all — STOP;
  that file needs a real replacement, which is out of this plan's scope.
- The dashboard charts break after removal (they shouldn't — confirms a hidden
  recharts dependency) — STOP and report.

## Maintenance notes

- If spreadsheet export is genuinely needed later, evaluate `exceljs` (maintained,
  no active CVEs) and scope it as its own plan — do not resurrect `xlsx`.
- If charts are added later, `recharts` is fine to reintroduce at a patched
  version, but verify its transitive `lodash` is clean at that time.
- A reviewer should confirm the lockfile diff only removes these two trees and
  doesn't accidentally bump unrelated packages.
