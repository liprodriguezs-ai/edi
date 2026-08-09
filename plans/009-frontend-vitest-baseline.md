# Plan 009: Install Vitest and seed a frontend test baseline

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd frontend && git diff --stat 74c0b0e..HEAD -- package.json vitest.config.ts`
> If `vitest` is already a dependency and tests exist, this plan may be partly
> done — reconcile rather than duplicate. Mismatch with "Current state" = STOP.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW
- **Depends on**: none (enables a frontend CI job after plan 008)
- **Category**: tests
- **Planned at**: frontend commit `74c0b0e`, 2026-06-11

## Why this matters

`frontend/package.json` declares `"test": "vitest run"` but **vitest is not
installed** and there are **zero test files** — `pnpm test` fails with "command
not found". The frontend has already shipped contract bugs (API calls 422'd
because a `sort`/`filter` field wasn't on the backend's Orion whitelist) that a
unit test would have caught. This plan installs the test runner and writes a
small, high-value seed of tests around the most fragile, most-trafficked code:
the Zod schemas and the shared CRUD/list hooks every module routes through.

## Current state

- `frontend/package.json` `scripts`: `"test": "vitest run"`, `"test:watch": "vitest"`.
  `vitest` is NOT in `dependencies`/`devDependencies`. No `vitest.config.ts`.
- Stack: React 19, Vite, TypeScript strict, path alias `@` → `src` (defined in
  `vite.config.ts` `resolve.alias`). Tailwind v4.
- High-value targets:
  - Zod schemas: `frontend/src/modules/*/schema.ts` — each defines entity
    interfaces + a Zod form schema (e.g. `unitFormSchema`,
    `buildUnitFormSchema(requiresFloorIds)` in `src/modules/unit/schema.ts`).
  - Shared hooks: `frontend/src/shared/hooks/useBaseList.*` and
    `useBaseCrud.*` — the list/CRUD plumbing all modules use; the search-param
    shaping here (sort/filter/limit) is exactly what caused the 422 bugs.
  - API client: `frontend/src/shared/api/apiClient.ts` (axios instance,
    interceptors).

## Commands you will need

| Purpose          | Command (from `frontend/`)                  | Expected             |
|------------------|---------------------------------------------|----------------------|
| Install vitest   | `pnpm add -D vitest @testing-library/react @testing-library/jest-dom jsdom` | exit 0 |
| Run tests        | `pnpm test`                                 | runs (pass)          |
| Typecheck        | `pnpm exec tsc -b`                           | exit 0 (no new errors)|
| Build            | `pnpm build`                                 | exit 0               |

## Scope

**In scope**:
- `frontend/package.json` (add devDeps; the `test` script already exists)
- `frontend/vitest.config.ts` (create)
- `frontend/src/test/setup.ts` (create — jest-dom matchers)
- A small set of new `*.test.ts(x)` files next to the code they cover (see Test plan)

**Out of scope**:
- Refactoring the hooks/schemas under test — tests only.
- Achieving any coverage percentage — this is a seed, not a campaign.
- Wiring vitest into CI — that's a follow-up after plan 008 (note it, don't do it here).

## Git workflow

- Branch: `advisor/009-frontend-vitest-baseline`
- Conventional commit: `test(frontend): install vitest and seed schema/hook tests`
- No push/PR unless instructed. `frontend/` is a submodule.

## Steps

### Step 1: Install the runner

`pnpm add -D vitest @testing-library/react @testing-library/jest-dom jsdom` → exit 0.

### Step 2: Configure vitest

Create `frontend/vitest.config.ts`. It must reuse the `@` → `src` alias and use
the jsdom environment:
```ts
import { defineConfig } from 'vitest/config'
import path from 'path'

export default defineConfig({
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
  },
})
```
Create `frontend/src/test/setup.ts`:
```ts
import '@testing-library/jest-dom/vitest'
```

**Verify**: `pnpm test` runs (it will report "no tests found" — that's expected
before Step 3, and means the runner works).

### Step 3: Write the seed tests

Write small, deterministic tests (no network):

1. **Zod schema tests** — `src/modules/unit/schema.test.ts`:
   - Valid unit payload parses.
   - A unit whose type requires a floor (build the schema with
     `buildUnitFormSchema(new Set([typeId]))`) FAILS validation when `tower_id`
     or `floor` is missing, and PASSES when present. This directly encodes the
     `requires_floor` rule.
   Pick 1–2 more module schemas with non-trivial rules (e.g.
   `src/modules/provider/schema.ts` if `phone` is required) and add a valid +
   invalid case each.

2. **Hook search-param test** — find where `useBaseList` builds its request
   payload (`src/shared/hooks/`). If the param-shaping is a pure function, test
   it directly: given a sort/filter input, assert the produced body matches the
   Orion contract (`{ sort: [{field, direction}], filters: [...], limit, page }`).
   If it's not easily isolated, test the smallest pure helper you can reach and
   note in the test file what remains uncovered. Do NOT mock the whole network
   stack for this seed.

**Verify**: `pnpm test` → all new tests pass.

### Step 4: Confirm nothing else broke

- `pnpm exec tsc -b` → exit 0, no new errors.
- `pnpm build` → exit 0.

## Test plan

The deliverable IS tests. Cover, at minimum:
- Unit schema: valid parse, `requires_floor` enforcement (missing → fail, present → pass).
- One more module schema: a valid case and a rule-violating case.
- `useBaseList`/`useBaseCrud` request shaping: at least one assertion that the
  produced search body matches the Orion field contract.

Use no real network. Model component/hook tests on Testing Library's standard
`renderHook`/`render` patterns.

## Done criteria

ALL must hold (from `frontend/`):

- [ ] `vitest` is in `devDependencies`; `vitest.config.ts` and `src/test/setup.ts` exist.
- [ ] `pnpm test` exits 0 and runs ≥4 test cases across ≥2 files.
- [ ] At least one test encodes the `requires_floor` rule and at least one encodes the `useBaseList` search-body contract.
- [ ] `pnpm exec tsc -b` exits 0 with no new errors; `pnpm build` exits 0.
- [ ] No out-of-scope source files modified (tests + config only).
- [ ] `plans/README.md` status row updated.

## STOP conditions

- `vitest` is already installed and configured (drift) — switch to adding the
  seed tests only, and say so in your report.
- `useBaseList`'s request shaping is so entangled with React/Zustand/axios that
  testing it requires mocking the entire stack — STOP at testing the schemas plus
  whatever pure helper is reachable, and report that hook-level testing needs a
  small refactor (extract the param builder) as a follow-up.
- `pnpm exec tsc -b` reveals the project's `tsc -b` build cannot run in isolation
  (composite project references) — report it; you may need `tsc --noEmit -p tsconfig.app.json` instead.

## Maintenance notes

- After this lands and plan 008 is in place, add a `frontend-test` CI job
  (`pnpm install && pnpm test`) and gate deploy on it.
- Grow coverage by adding a schema test whenever a 422 contract bug is found —
  that's the highest-signal place for this app.
- A reviewer should confirm tests are deterministic (no real timers/network) and
  that the `requires_floor` test would actually fail if the rule regressed.
