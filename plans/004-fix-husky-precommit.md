# Plan 004: Repair the Husky pre-commit hook (`npx run` → `pnpm run`)

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md` when
> done.
>
> **Drift check (run first)**: from the repo root,
> `cd frontend && git diff --stat 74c0b0e..HEAD -- .husky/ package.json`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: dx
- **Planned at**: frontend commit `74c0b0e`, 2026-06-11

## Why this matters

The pre-commit hook runs `npx run typecheck`, `npx run pretty-quick`,
`npx run lint-staged` — but `npx run` is **not a valid command** (`npx` executes
packages; running a package-json script is `pnpm run` / `npm run`). So the hook
errors out on every commit regardless of code quality, which is why the team has
been committing with `--no-verify` and untyped/unformatted code reaches history.
Fixing three lines restores the quality gate. (This repo uses **pnpm** — the
lockfile and `packageManager` field confirm it.)

## Current state

- `frontend/.husky/pre-commit` — exactly these three lines:
  ```sh
  npx run typecheck
  npx run pretty-quick
  npx run lint-staged
  ```
- `frontend/.husky/commit-msg` — already correct (`./node_modules/.bin/commitlint`); do not touch.
- `frontend/package.json` `scripts` — confirm a `typecheck` script exists. The
  build script is `tsc -b && vite build`; there may or may not be a standalone
  `typecheck`. Check before assuming.

## Commands you will need

| Purpose            | Command (from `frontend/`)              | Expected               |
|--------------------|-----------------------------------------|------------------------|
| List scripts       | `cat package.json` (read `scripts`)     | see script names       |
| Run typecheck      | `pnpm run typecheck` OR `pnpm exec tsc -b` | exit 0 (or pre-existing errors — see STOP) |
| Run lint-staged    | `pnpm exec lint-staged --help`          | prints help (installed)|
| Simulate hook      | `sh .husky/pre-commit`                  | runs without `command not found` |

## Scope

**In scope**:
- `frontend/.husky/pre-commit`
- `frontend/package.json` — ONLY if a `typecheck` script must be added (see Step 1)

**Out of scope**:
- `.husky/commit-msg` and any other hook.
- The `lint-staged` configuration content (unless it's entirely missing — then STOP and report, don't invent one).

## Git workflow

- Branch: `advisor/004-fix-husky-precommit`
- Conventional commit: `fix(dx): repair pre-commit hook to use pnpm run`
- No push/PR unless instructed. `frontend/` is a submodule.

## Steps

### Step 1: Confirm the scripts the hook calls actually exist

Read `frontend/package.json` `scripts`. The hook calls `typecheck`,
`pretty-quick`, `lint-staged`.

- If a `typecheck` script exists, keep using it.
- If it does NOT exist, add one: `"typecheck": "tsc -b --noEmit"` (or `"tsc -b"`
  if `--noEmit` conflicts with the project's `tsc -b` build references — test it).
- `pretty-quick` and `lint-staged` are typically run as packages, not scripts.

### Step 2: Rewrite the hook to valid pnpm invocations

Replace the three lines so each runs correctly. Prefer `pnpm exec` for the
package binaries and `pnpm run` for scripts:

```sh
pnpm run typecheck
pnpm exec pretty-quick --staged
pnpm exec lint-staged
```

(Use `pretty-quick --staged` so it only formats staged files, matching its
pre-commit purpose. If `lint-staged` already runs prettier, you may drop the
explicit `pretty-quick` line to avoid double-formatting — read the `lint-staged`
config to decide; if unsure, keep both, it's harmless.)

### Step 3: Simulate the hook

**Verify**:
- `sh .husky/pre-commit` runs without `command not found` / `unknown command`.
  It may exit non-zero if there are real pre-existing typecheck errors — that's a
  separate problem (see STOP). The success criterion here is that each line
  *invokes its tool*, not that the tree is clean.

## Test plan

No automated test — this is a git-hook script. Manual verification:
- Stage a deliberately mis-formatted `.ts` file, attempt a commit WITHOUT
  `--no-verify`, and confirm the hook formats it (or blocks the commit). Then
  revert the experiment.

## Done criteria

ALL must hold (from `frontend/`):

- [ ] `grep -c "npx run" .husky/pre-commit` → `0`.
- [ ] `sh .husky/pre-commit` does not emit `command not found` / `unknown command "run"`.
- [ ] Each tool the hook references resolves (`pnpm exec lint-staged --help` and `pnpm exec pretty-quick --help` print help; `pnpm run typecheck` invokes tsc).
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- `.husky/pre-commit` no longer matches the three `npx run` lines (drift).
- `pnpm run typecheck` reveals a large number of pre-existing TypeScript errors
  the hook would now block on. **Do not fix them in this plan** — report the
  count and stop; making the hook strict against a red baseline is a separate
  decision (the hook can be staged: format-only first, typecheck later).
- There is no `lint-staged` configuration anywhere (neither in `package.json`
  nor a `.lintstagedrc`) — report it; don't author one blind.

## Maintenance notes

- This repo is pnpm-only; never reintroduce `npx run`. If CI later runs the same
  checks (see plan 008), keep the hook and CI in sync on which tools run.
- A reviewer should confirm the hook was tested WITHOUT `--no-verify` on a real
  staged change, and that it doesn't now block every commit on a pre-existing
  lint/type backlog.
