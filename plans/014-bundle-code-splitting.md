# Plan 014: Code-split the frontend bundle and lazy-load Firebase

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd frontend && git diff --stat 74c0b0e..HEAD -- vite.config.ts src/app/layout/DashboardLayout.tsx src/shared/firebase`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW–MED
- **Depends on**: best done AFTER plan 005 (removing dead `recharts` shrinks the bundle first) — not strictly required
- **Category**: perf
- **Planned at**: frontend commit `74c0b0e`, 2026-06-11

## Why this matters

The production build emits a single `index` chunk of ~884 KB (~249 KB gzip). Route
modules are lazy-loaded correctly, but heavy vendor libraries land in that one
initial chunk, and `useFirebaseMessaging` is imported **statically** in
`DashboardLayout`, so the Firebase SDK (Messaging + the Firestore/gRPC tree it
drags in, ~300–400 KB) ships to every user on first load — including users who
never receive a push. Two changes cut the initial payload: split vendors into
their own chunks, and load Firebase only when it's actually needed.

## Current state

- `frontend/vite.config.ts` — `defineConfig` has plugins, a dev proxy, and the
  `@`→`src` alias, but **no `build.rollupOptions.output.manualChunks`** (no vendor
  splitting strategy). (Note: the build uses Rolldown-flavored Vite; the option
  may be `build.rollupOptions.output.manualChunks` or the Rolldown equivalent —
  verify against the installed Vite's docs before assuming.)
- `frontend/src/app/layout/DashboardLayout.tsx` (~line 8) — a STATIC import:
  `import { useFirebaseMessaging } from ...`, pulling Firebase into the initial
  chunk for every authenticated user.
- `frontend/src/shared/firebase/firebaseConfig.ts` — initializes Firebase
  (`getMessaging`, `isSupported`). The app uses Firebase **only** for FCM web
  push, not Firestore — but the dependency tree may still include Firestore/gRPC.
- Build output line at audit: `dist/assets/index-*.js 884.86 kB │ gzip: 249.59 kB`
  with a "chunks larger than 500 kB" warning.

## Commands you will need

| Purpose       | Command (from `frontend/`)              | Expected                         |
|---------------|-----------------------------------------|----------------------------------|
| Build         | `pnpm build`                            | exit 0; report chunk sizes       |
| Typecheck     | `pnpm exec tsc -b`                      | exit 0                           |
| Dev smoke     | `pnpm dev` then open the app            | dashboard + push still work      |
| Inspect chunks| read the `pnpm build` size table        | initial `index` chunk smaller    |

## Scope

**In scope**:
- `frontend/vite.config.ts` (add manualChunks / vendor splitting)
- `frontend/src/app/layout/DashboardLayout.tsx` (defer Firebase to dynamic import)
- Possibly a small wrapper around `useFirebaseMessaging` to load it lazily

**Out of scope**:
- Removing Firebase or switching push providers.
- Route-level lazy loading (already done — don't rework it).
- The dead `recharts` dep (plan 005 handles it).

## Git workflow

- Branch: `advisor/014-bundle-code-splitting`
- Conventional commit: `perf(frontend): split vendor chunks and lazy-load Firebase`
- No push/PR unless instructed. `frontend/` is a submodule.

## Steps

### Step 1: Capture the baseline

Run `pnpm build` and record the current chunk sizes (the `index` chunk and total).
You'll compare against this at the end.

### Step 2: Add vendor chunk splitting

In `vite.config.ts`, add an `output.manualChunks` (or the installed Vite's
equivalent) that isolates the heavy vendors into their own chunks, e.g. group
`firebase`/`@firebase/*` into a `firebase` chunk and `@dnd-kit/*` into a `dnd`
chunk. Confirm the exact option name against the installed Vite version's docs
before writing it — if this Vite build uses a different splitting API, use that
one. Example shape (verify API):
```ts
build: {
  rollupOptions: {
    output: {
      manualChunks: {
        firebase: ['firebase/app', 'firebase/messaging'],
        dnd: ['@dnd-kit/core', '@dnd-kit/sortable'],
      },
    },
  },
},
```

### Step 3: Lazy-load Firebase in DashboardLayout

Replace the static `import { useFirebaseMessaging }` with a deferred load so the
Firebase SDK is fetched only when messaging is actually initialized (e.g. after
the service worker reports support, or behind an effect that `import()`s the
module). Keep the user-facing behavior identical — push registration still
happens for supported browsers, just not in the initial synchronous bundle.

### Step 4: Rebuild and compare

- `pnpm build` → exit 0; the initial `index` chunk is smaller than the baseline,
  and Firebase is now in its own chunk that is NOT part of the initial load (or is
  loaded on demand).
- `pnpm exec tsc -b` → exit 0.

### Step 5: Smoke-test

`pnpm dev`, log in, open the dashboard. Confirm the app renders and (in a
push-supported browser) notification registration still occurs. No console errors.

## Test plan

No unit test (build/bundling change). Verification is the build output + a manual
smoke test:
- Build size table shows the initial chunk shrank and Firebase split out.
- Manual: dashboard renders; push notification registration still works in a
  supported browser; no new console errors.

If plan 009 (vitest) has landed, optionally add a trivial test that the lazy
Firebase wrapper resolves — but this is not required.

## Done criteria

ALL must hold (from `frontend/`):

- [ ] `vite.config.ts` defines a vendor-splitting strategy isolating Firebase (and ideally dnd-kit).
- [ ] `DashboardLayout.tsx` no longer statically imports `useFirebaseMessaging`; Firebase loads on demand.
- [ ] `pnpm build` exits 0 and the initial `index` chunk is smaller than the recorded baseline.
- [ ] `pnpm exec tsc -b` exits 0.
- [ ] Manual smoke: dashboard renders, push still registers, no new console errors.
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- "Current state" excerpts don't match (drift).
- The installed Vite version doesn't support the `manualChunks` API as written and
  you can't find its equivalent — STOP and report the Vite version; do the
  Firebase lazy-load (Step 3) alone if it's independently safe, and defer chunk
  splitting.
- Lazy-loading Firebase breaks push registration timing (notifications stop
  registering) — revert Step 3, keep Step 2, and report; correctness of push
  beats the bundle win.

## Maintenance notes

- This repo runs **Vite 8 beta** in production (a separate finding) — the bundling
  API may shift on the next beta bump; revisit `manualChunks` if the build output
  changes shape after a Vite update.
- If Firestore is confirmed unused, a follow-up can ensure the Firebase import
  surface is messaging-only so the Firestore/gRPC tree never enters any chunk.
- A reviewer should compare the before/after build size tables in the PR and
  confirm push still works in a real browser, not just that the build is green.
