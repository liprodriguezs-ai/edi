# Plan 064: Restore the frontend git hooks and fix the 4 real React Compiler lint errors

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `cd frontend && git config --show-origin core.hooksPath` and
> `git -C frontend diff --stat 8f3675b..HEAD -- src/components/ui/sidebar.tsx src/shared/auth/SessionProvider.tsx src/shared/components/ChangePasswordModal.tsx src/shared/components/ProfilePhotoModal.tsx`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: DX + bug (correctness — cascading renders)
- **Planned at**: frontend `8f3675b`, 2026-07-01

## Why this matters

The frontend's `core.hooksPath` points at a directory that doesn't exist
(`.../monorepo/frontend/.husky`), so pre-commit (typecheck + format + lint-staged) and
commit-msg (conventional-commit) hooks have not been running on this checkout. That
plausibly explains why 22 lint errors sit uncaught. Of those, 5 are known false positives
in `frontend/emails/*.tsx` (Blade-placeholder props), but 4 are REAL React Compiler
diagnostics flagging correctness smells: an impure call during render and three
"setState synchronously within an effect → cascading renders" cases. Restoring the hooks
stops the bleed; fixing the 4 real errors clears the genuine issues.

## Current state

- `frontend/.husky/pre-commit` is correct (`pnpm run typecheck && pnpm run pretty-quick && pnpm run lint-staged`);
  `frontend/package.json` has `"prepare": "husky"`. But `git config --show-origin core.hooksPath`
  (in `frontend/`) resolves to a non-existent `.../monorepo/frontend/.husky` path (verified
  three ways by the audit: path doesn't exist, `monorepo` parent doesn't exist, and it's
  persisted in the submodule git config).
- `pnpm lint` (measured): 54 problems (22 errors, 32 warnings), 0 auto-fixable. The 4 real
  errors:
  - `src/components/ui/sidebar.tsx:610` — "Cannot call impure function during render."
  - `src/shared/auth/SessionProvider.tsx:27` — setState synchronously within an effect.
  - `src/shared/components/ChangePasswordModal.tsx:34` — same.
  - `src/shared/components/ProfilePhotoModal.tsx:32` — same.
  - The other 17 errors are `frontend/emails/*.tsx` unused-var false positives (Blade
    placeholder props) — see Out of scope.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Hook origin | `cd frontend && git config --show-origin core.hooksPath` | after fix: `.husky/_` (or valid path) |
| Reinstall hooks | `cd frontend && pnpm install` | re-runs `prepare` (husky) |
| Lint | `cd frontend && pnpm lint` | error count drops from 22 → ≤18 (the 4 real ones gone) |
| Typecheck | `cd frontend && pnpm exec tsc --noEmit` | exit 0 |
| Tests | `cd frontend && pnpm test -- --run` | 390 pass |

## Scope

**In scope**:
- Restore `core.hooksPath` to the real `.husky` (via `pnpm install` re-running `prepare`,
  or `git config core.hooksPath .husky/_` — use whichever husky v9 expects).
- Fix the 4 real React Compiler errors (sidebar impure-call; the 3 effect-setState cascades)
  using the framework-correct pattern (see Suggested toolkit).

**Out of scope**:
- The 5 `frontend/emails/*.tsx` unused-var errors — these are false positives from the
  React-Email→Blade convention (props exist only to emit `{{ $var }}` placeholders). Do NOT
  "fix" them by deleting props — that would break the compiled Blade. If they're noise,
  the right fix is an eslint-disable scoped to `frontend/emails/**` in the ESLint config;
  optional, note it but don't let it block the real fixes.
- The 32 warnings (unless trivially adjacent).

## Suggested executor toolkit

- If available, invoke the `vercel-react-best-practices` skill or the `react-doctor` skill
  for the effect-setState fixes — the correct pattern is usually "derive during render" or
  "adjust state in an event/prop-change handler," not setState-in-effect.

## Steps

### Step 1: Restore the hooks

`cd frontend && pnpm install` (re-runs the `prepare` → `husky` script). Confirm
`git config --show-origin core.hooksPath` now points at the real `.husky/_` inside the
submodule. Test with a throwaway commit that the pre-commit runs (then discard it).

**Verify**: `git config core.hooksPath` resolves to an existing dir; a trial commit
triggers typecheck/lint-staged.

### Step 2: Fix `sidebar.tsx:610` (impure call during render)

Read the exact line; the diagnostic means a side-effecting/impure call happens in render
body. Move it into a `useMemo`/event handler/effect as appropriate. (This is vendored
shadcn — keep the change minimal and local.)

**Verify**: `pnpm lint` no longer reports `sidebar.tsx:610`.

### Step 3: Fix the 3 effect-setState cascades

For `SessionProvider.tsx:27`, `ChangePasswordModal.tsx:34`, `ProfilePhotoModal.tsx:32`:
replace synchronous setState-in-effect with the correct pattern (derive during render, or
adjust-state-on-prop-change, or guard the effect). Preserve behavior — these are auth/
modal state paths; run the tests.

**Verify**: `pnpm lint` clears those three; `pnpm exec tsc --noEmit` exit 0; `pnpm test -- --run` 390 pass.

### Step 4: Confirm the count dropped

**Verify**: `pnpm lint` error count is now ≤ 18 (22 minus the 4 real ones), warnings
unchanged, and no NEW problems introduced.

## Done criteria

- [ ] `core.hooksPath` points at a valid `.husky` dir; pre-commit runs on a trial commit
- [ ] The 4 real React Compiler errors are gone from `pnpm lint`
- [ ] `pnpm exec tsc --noEmit` exit 0
- [ ] `pnpm test -- --run` 390 pass (no behavior regression in auth/modals)
- [ ] Only the hook config + the 4 component files modified (+ optional emails eslint-disable)
- [ ] `plans/README.md` row updated

## STOP conditions

- Fixing an effect-setState changes observable behavior (e.g. a modal no longer resets on
  open) and a test fails — STOP; get the pattern right (this exact class of "fix" caused a
  regression in a prior cycle — see plans/README ciclo-4 plan 047 note) rather than forcing
  the lint green.
- `pnpm install` does not restore `core.hooksPath` (husky v9 quirk in a submodule) — set it
  explicitly with `git config core.hooksPath .husky/_` and report.

## Maintenance notes

- With hooks restored, future commits enforce typecheck/lint — expect the 32 warnings to
  start mattering; a follow-up may burn them down.
- Reviewer: verify the effect fixes use derive-during-render / event-handler patterns, not
  a new effect that re-introduces a different cascade.
- The emails/*.tsx false positives are a lint-config gap, not code bugs — track separately.
