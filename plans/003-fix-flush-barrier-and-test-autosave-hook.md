# Plan 003: Make the screening autosave flush barrier honest (no hang on frozen, no false success on network failure) and put the hook under test

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat dbe3682..HEAD -- frontend/src/modules/screening/hooks/useScreeningApplication.ts frontend/src/modules/screening/store`
> Note: this file is currently UNTRACKED (the clinical core is uncommitted), so
> the diff may be empty by definition. In that case compare the "Current state"
> excerpts against the live file; on a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW (confined to one hook; existing page tests cover hydration)
- **Depends on**: none (frontend-only; plan 001 gives you `make test-front` but raw commands are listed)
- **Category**: bug + tests
- **Planned at**: commit `dbe3682`, 2026-08-25

## Why this matters

`useScreeningApplication` (~324 lines) is the ONLY path by which a clinician's screening answers reach the server: debounced autosave, exponential backoff, an awaited "flush barrier" that must drain the queue before scoring. It has **zero direct tests**, and reading it end-to-end surfaces two real defects in the barrier:

1. **Hang on frozen**: `flushNow()` returns early when the screening is frozen *without resolving the waiters*, so `score()`'s `await flushBarrier()` never settles. Clicking "Calificar" on a screening frozen by another session does nothing, forever, with no feedback.
2. **False success on network failure**: when the save request fails, the catch schedules a backoff retry — but control then falls through to `resolveFlushWaiters()`, so the barrier resolves while the queue is still unsent. `score()` proceeds, the server answers 422 `INCOMPLETE_ANSWERS`, and the clinician is told items are missing that they *did* answer. On a flaky rural connection — the realistic field condition — this pushes users to re-enter answers.

The fix is small; the tests are the larger half and they protect the whole save path (debounce collapse, backoff, dirty-during-flight, unmount cleanup) going forward.

## Current state

- `frontend/src/modules/screening/hooks/useScreeningApplication.ts` — the hook. Constants at the top of the file: `DEBOUNCE_MS = 750`, `BACKOFF_INITIAL_MS`/`BACKOFF_CAP_MS` (~1s/15s — read the file for exact values).
- The two defects, verbatim (line numbers as of `dbe3682` + working tree):

```ts
// :50-65 — early returns that never resolve waiters
const flushNow = useCallback(async () => {
    const id = currentScreeningId.current
    const state = useScreeningDraftStore.getState()
    if (!id || state.syncStatus === 'frozen') return          // ← BUG 1: waiters never resolve

    const snapshot = state.takePendingSnapshot()
    const hasWork = Object.keys(snapshot.answers).length > 0 || Object.keys(snapshot.riskFactors).length > 0
    if (!hasWork) {
      resolveFlushWaiters()
      return
    }

    if (inFlightRef.current) {
      dirtyDuringFlightRef.current = true
      return                                                  // (recovers via re-entry at :114-116 — fragile but works)
    }
```

```ts
// :105-119 — failure path falls through to resolve
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) { ... return }
      // Network/other failure: retain the queue, retry with backoff.
      state.setSyncStatus('offline')
      const delay = Math.min(backoffDelayRef.current, BACKOFF_CAP_MS)
      backoffDelayRef.current = Math.min(delay * 2, BACKOFF_CAP_MS)
      backoffTimerRef.current = setTimeout(() => void flushNow(), delay)
    } finally {
      inFlightRef.current = false
    }

    if (dirtyDuringFlightRef.current) {
      dirtyDuringFlightRef.current = false
      void flushNow()
    } else {
      resolveFlushWaiters()                                   // ← BUG 2: resolves after a FAILED save too
    }
```

```ts
// :127-134 — the barrier
  const flushBarrier = useCallback(() => {
    if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current)
    return new Promise<void>((resolve) => {
      flushWaitersRef.current.push(resolve)
      void flushNow()
    })
  }, [flushNow])
```

```ts
// :264-270 — the consumer
  const score = useCallback(async () => {
    const id = currentScreeningId.current
    if (!id) return
    await flushBarrier()               // hangs (bug 1) or proceeds on unsent queue (bug 2)
    store.setScorePhase('scoring')
    try {
      await ScreeningResource.score(id)
```

- Store API used by the hook (from `frontend/src/modules/screening/store/screeningDraftStore.ts`): `useScreeningDraftStore.getState()`, `takePendingSnapshot()`, `clearPending(snapshot)`, `setSyncStatus('idle'|'saving'|'saved'|'offline'|'frozen')`, `markFrozen()`, `scorePhase`, `setScorePhase`, `clearResult()`. The store has its own good test file: `frontend/src/modules/screening/store/screeningDraftStore.test.ts` (192 lines) — use it to learn the store setup pattern.
- Existing page-level tests: `frontend/src/modules/screening/ScreeningApplyPage.test.tsx` (3 cases, hydration only). Existing hook-test pattern in the repo: `frontend/src/shared/hooks/useBaseList.test.ts` (mocks resources, uses `renderHook`).
- Repo conventions: TypeScript strict, NO `any` (hard rule), vitest + Testing Library, single quotes, toasts via `import { toast } from '@/shared/hooks/useToast'`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Typecheck | `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit` | exit 0 |
| This test file | `cd frontend && pnpm exec vitest run src/modules/screening/hooks/useScreeningApplication.test.ts` | all pass |
| Screening module tests | `cd frontend && pnpm exec vitest run src/modules/screening` | all pass |
| Full frontend | `cd frontend && pnpm exec vitest run` | all pass |

## Scope

**In scope**:
- `frontend/src/modules/screening/hooks/useScreeningApplication.ts`
- `frontend/src/modules/screening/hooks/useScreeningApplication.test.ts` (create)
- `plans/README.md` (status row)

**Out of scope** (do NOT touch):
- `frontend/src/modules/screening/store/screeningDraftStore.ts` and its test — the store's queue semantics are correct and tested; the bug is in the orchestration.
- `ScreeningResource` / API layer, `ScreeningApplyPage.tsx` and its test.
- Any change to the autosave protocol itself (debounce interval, backoff policy, payload shape) — the server contract stays identical.
- Do NOT add persistence (localStorage/IndexedDB) — the offline-durability question is a separate product decision (see plans/README.md, direction backlog).

## Git workflow

The working tree holds ~218 uncommitted files of unrelated in-flight work. Do NOT `git add`/`commit`/`stash` or branch. Leave edits uncommitted; report the file list.

## Steps

### Step 1: Write the failing tests (TDD — red first)

Create `frontend/src/modules/screening/hooks/useScreeningApplication.test.ts`. Setup: `vi.useFakeTimers()`; mock `ScreeningResource` (`vi.mock`) so `answer` is controllable per-test (resolve / reject with a plain network `Error` / reject with `ApiError` status 409); mock `react-router-dom`'s `useNavigate` and the toast module; reset the real `useScreeningDraftStore` between tests (see how `screeningDraftStore.test.ts` resets state). Hydrate the store with a minimal screening + item bank (copy the smallest fixture shape from `screeningDraftStore.test.ts`). Advance time with `await vi.advanceTimersByTimeAsync(...)` — never real waits.

Cases (names indicative):

1. `collapses rapid answers into one PATCH` — call `selectAnswer` 3× within 750 ms → exactly 1 `ScreeningResource.answer` call carrying all 3.
2. `retries with exponential backoff and retains the queue` — make `answer` reject (network error) twice then resolve; assert calls happen at ~1s and ~2s after the failures, queue intact between, `syncStatus` goes `offline` → `saved`.
3. `flushBarrier resolves only after pending + dirty-during-flight work is flushed` — trigger a flush, dirty the store mid-flight, await the barrier, assert the second request completed before the barrier settled.
4. **`score() does not fire while the queue is unflushed after a failed save`** — make `answer` reject (network error), call `score()`; assert `ScreeningResource.score` was NOT called and an error toast/state was produced. ← fails today (bug 2).
5. **`flushBarrier settles when the screening is frozen`** — set store to frozen, call `score()` with a timeout guard (e.g. `await Promise.race([scorePromise, timeoutFlag])`); assert it settles (no hang) and `score` was not called. ← fails today (bug 1: the promise never settles).
6. `unmount clears both timers` — unmount with a pending debounce and a scheduled backoff; advance timers; assert no further `answer` calls.

**Verify**: `pnpm exec vitest run src/modules/screening/hooks/useScreeningApplication.test.ts` → cases 1–3, 6 pass; **4 and 5 fail** (red). If 4 or 5 passes, the code drifted — STOP.

### Step 2: Make the barrier settle with a result

Change the barrier/flush contract from `Promise<void>` to `Promise<{ flushed: boolean }>`:

- `flushNow` returns `boolean` (true = queue drained, false = failed/frozen/aborted). Every exit path must settle waiters exactly once:
  - `!id || frozen` → resolve waiters with `flushed: false` (add `resolveFlushWaiters(false)`), return.
  - `!hasWork` → resolve `true`.
  - in-flight early return → unchanged (the re-entry at the end of the running invocation settles).
  - 409-frozen catch branch → already resolves; make it resolve `false`.
  - network-failure branch → schedule the backoff retry as today, but do NOT resolve the waiters (move `resolveFlushWaiters()` so the failure path skips it) — **except**: if there are waiters, resolve them with `flushed: false` instead of leaving them for the retry. (Design decision, deliberate: a barrier caller like `score()` wants an immediate honest answer, not a wait of up to 15 s; background retries continue independently.)
  - success path → resolve `true` (after the dirty-during-flight re-flush completes, as today).
- `resolveFlushWaiters(flushed: boolean)` passes the value through; `flushWaitersRef` becomes `Array<(r: { flushed: boolean }) => void>`.

### Step 3: Make `score()` honor the barrier result

```ts
const { flushed } = await flushBarrier()
if (!flushed) {
  const frozen = useScreeningDraftStore.getState().syncStatus === 'frozen'
  if (!frozen) toast.error('No se pudieron guardar tus respuestas; revisa tu conexión e intenta de nuevo')
  return
}
```

(When frozen, `flushNow`'s 409 path / `markFrozen` already toasts + redirects — don't double-toast.) UI text in Spanish (hard rule: UI siempre en español).

**Verify** (Steps 2–3 together): the full new test file passes (6/6 green); `pnpm exec tsc -p tsconfig.app.json --noEmit` → exit 0.

### Step 4: Full regression

**Verify**: `pnpm exec vitest run src/modules/screening` → all pass (including `ScreeningApplyPage.test.tsx`, `screeningDraftStore.test.ts`). Then `pnpm exec vitest run` → full frontend suite passes. Then `pnpm build` → exit 0.

## Test plan

The new file from Step 1 IS the test plan: 6 cases covering debounce collapse, backoff+retention, barrier drain, barrier-honesty on failure, frozen no-hang, unmount cleanup. Pattern files: `screeningDraftStore.test.ts` (store fixtures/reset), `useBaseList.test.ts` (hook + mocked resource). All timer control via fake timers.

## Done criteria

- [ ] `useScreeningApplication.test.ts` exists with ≥6 cases; all pass
- [ ] Cases 4 and 5 demonstrably failed before the fix (state this in your report with the red-run output)
- [ ] `pnpm exec tsc -p tsconfig.app.json --noEmit` exit 0; no `any` introduced (`grep -n ": any\|as any" frontend/src/modules/screening/hooks/useScreeningApplication.ts` → empty)
- [ ] `pnpm exec vitest run` full suite passes; `pnpm build` exit 0
- [ ] `git status` shows only the hook + new test file changed beyond pre-existing unrelated changes
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back if:

- The hook no longer matches the "Current state" excerpts (drifted since planning).
- Test 4 or 5 passes BEFORE any fix.
- Fixing the barrier seems to require changing `screeningDraftStore.ts` — the store is out of scope; report what you needed from it.
- `ScreeningApplyPage.test.tsx` fails after your change and the failure is not obviously the new toast — page behavior may depend on the old (broken) resolve semantics in a way this plan didn't foresee.

## Maintenance notes

- If offline persistence (IndexedDB drafts) is built later, the `{ flushed }` contract is the seam it plugs into: a failed flush would enqueue durably instead of toasting.
- Reviewer scrutiny: every exit path of `flushNow` must settle waiters exactly once — check the 409 branch and the dirty-during-flight re-entry especially (double-resolve is harmless with the current implementation but masks logic errors).
- Deferred deliberately: the `visibilitychange` best-effort flush and the debounce/backoff constants are untouched.
