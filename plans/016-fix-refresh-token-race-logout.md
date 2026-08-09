# Plan 016: Fix the refresh-token race that logs users out under rapid navigation

> **Executor instructions**: Follow step by step; run every verification and
> confirm the expected result before moving on. On a "STOP condition", stop and
> report. When done, update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd frontend && git diff --stat 1bcfbe3..HEAD -- src/shared/api/apiClient.ts`
> If the file changed since this plan was written, compare the "Current state"
> excerpts to the live code first; mismatch = STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED
- **Depends on**: best done after plan 009 (vitest) so a regression test is possible
- **Category**: bug
- **Planned at**: frontend commit `1bcfbe3`, 2026-06-11

## Why this matters

Under rapid navigation (e.g. clicking through ~10 pages quickly), the app
**spuriously logs the user out**. Reproduced live: an admin session dropped to
the login screen after ~10 fast navigations, twice. Root cause is a race in the
401 refresh handler: `ensureRefreshPromise()` clears the shared `refreshPromise`
in its `.finally()` the instant the refresh resolves, but `handle401Retry` reads
the module-level `refreshPromise` *after* calling `ensureRefreshPromise()`. If a
concurrent flow's refresh already resolved and nulled it, `await refreshPromise`
awaits `null`, yields `tokens = null`, and falls through to `logoutAndReject` —
logging the user out even though the refresh succeeded. With single-use refresh
tokens this is worsened by a second refresh firing with an already-consumed
token. The fix is to capture the refresh promise locally and await that, instead
of re-reading the shared variable after it may have been cleared.

## Current state

`frontend/src/shared/api/apiClient.ts`:

- `ensureRefreshPromise()` (~lines 92–103):
  ```ts
  function ensureRefreshPromise(): void {
    if (refreshPromise) return
    refreshPromise = refreshTokenRequest()
      .then((data) => data)
      .catch((err) => {
        refreshPromise = null
        throw err
      })
      .finally(() => {
        refreshPromise = null
      })
  }
  ```
- `handle401Retry()` (~lines 122–153):
  ```ts
  ensureRefreshPromise()

  try {
    const tokens = await refreshPromise        // <-- reads the SHARED var, may be null now
    if (tokens) {
      sessionStore.getState().setTokens({ token: tokens.access_token })
      originalConfig._retry = true
      return apiClient.request(originalConfig)
    }
  } catch {
    return logoutAndReject(error, status, getErrorMessage(error, 'Session expired'))
  }

  return logoutAndReject(error, status, message)   // <-- reached when tokens was null (the bug)
  ```
- `refreshPromise` is a module-level variable (declared near the top of the file).
  `refreshTokenRequest()` performs the refresh and resolves to `{ access_token }`
  (or similar — read its return type). `logoutAndReject(...)` (~line 80) clears
  `refreshPromise`, calls `sessionStore.logout()`, and redirects to `/login`.

## Commands you will need

| Purpose   | Command (from `frontend/`) | Expected           |
|-----------|----------------------------|--------------------|
| Typecheck | `pnpm exec tsc -b`         | exit 0             |
| Test      | `pnpm test`                | all pass           |
| Build     | `pnpm build`               | exit 0             |

## Scope

**In scope**:
- `frontend/src/shared/api/apiClient.ts` — the refresh dedup + `handle401Retry`.
- `frontend/src/shared/api/apiClient.test.ts` (create) — a regression test, IF the
  refresh logic can be exercised in isolation (see Test plan / STOP).

**Out of scope**:
- The backend refresh endpoint and token TTLs.
- The 403 handling, the request/response interceptors unrelated to 401 refresh.
- Any change to `sessionStore` beyond what already exists.

## Git workflow

- Branch: `advisor/016-fix-refresh-token-race-logout`
- Conventional commit: `fix(auth): await the captured refresh promise to stop spurious logout`
- No push/PR unless instructed. `frontend/` is a submodule.

## Steps

### Step 1: Return the promise from the dedup helper and await it locally

Change `ensureRefreshPromise()` to RETURN the shared promise (creating it once),
and have `handle401Retry` await the returned local reference — never re-read the
module variable after creating it:

```ts
function ensureRefreshPromise(): Promise<{ access_token: string }> {
  if (!refreshPromise) {
    refreshPromise = refreshTokenRequest().finally(() => {
      refreshPromise = null
    })
  }
  return refreshPromise
}
```
(Match the actual resolved type of `refreshTokenRequest()` — read it; it may be a
wider object. Keep the `.finally` that clears the shared var so the NEXT expiry
starts a fresh refresh, but the local reference returned to each caller keeps the
resolved value alive.)

In `handle401Retry`, replace the `ensureRefreshPromise(); ... await refreshPromise`
pair with:
```ts
try {
  const tokens = await ensureRefreshPromise()
  if (tokens) {
    sessionStore.getState().setTokens({ token: tokens.access_token })
    originalConfig._retry = true
    return apiClient.request(originalConfig)
  }
} catch {
  return logoutAndReject(error, status, getErrorMessage(error, 'Session expired'))
}
return logoutAndReject(error, status, message)
```

The key change: `await ensureRefreshPromise()` (a captured local) instead of
`await refreshPromise` (the shared var that may already be null).

### Step 2: Verify the surrounding error handling still holds

- A genuinely failed refresh still rejects → the `catch` runs → `logoutAndReject`.
  (The `.catch(... refreshPromise = null; throw err)` is now folded into `.finally`
  + the rejection propagating to each awaiter — confirm a rejected
  `refreshTokenRequest()` still reaches the `catch` in `handle401Retry`.)
- `originalConfig._retry` still guards against infinite retry loops (unchanged).

**Verify**:
- `pnpm exec tsc -b` → exit 0.
- `pnpm build` → exit 0.

### Step 3: Manual smoke — the original repro

Run `pnpm dev`, log in, and navigate rapidly through ~15 protected routes in
quick succession (e.g. dashboard → unit → statement → announcement → charge →
… ). The session must NOT drop to `/login`. Before the fix this logs out after
~10; after the fix it stays logged in.

## Test plan

If `refreshTokenRequest` / the 401 flow can be exercised with a mocked axios
(after plan 009 installs vitest), add `frontend/src/shared/api/apiClient.test.ts`:
- Simulate two concurrent requests receiving 401 with a single in-flight refresh;
  assert `refreshTokenRequest` is called **once** and BOTH requests retry with the
  new token (neither logs out).
- Simulate a third 401 arriving just after the first refresh resolves; assert it
  does not trigger a spurious logout.

If the module's singletons (`apiClient`, `sessionStore`, `refreshPromise`) make
isolated testing impractical, STOP at the manual smoke and note that a unit test
needs a small refactor (inject the refresh fn) as a follow-up — do not contort
the test.

**Verify**: `pnpm test` → all pass (including the new test if added).

## Done criteria

ALL must hold (from `frontend/`):

- [ ] `ensureRefreshPromise()` returns the promise; `handle401Retry` awaits the returned local, not the shared `refreshPromise`.
- [ ] `grep -n "await refreshPromise" src/shared/api/apiClient.ts` returns no matches.
- [ ] `pnpm exec tsc -b` exits 0; `pnpm build` exits 0.
- [ ] Manual smoke: rapidly navigating ~15 routes does NOT log the user out.
- [ ] `pnpm test` passes (plus the new concurrency test if isolation allowed it).
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- "Current state" excerpts don't match (drift).
- A failed refresh no longer logs the user out after the change (the `catch` path
  broke) — that would leave a stuck session; revert and report.
- The manual smoke still logs out after the change — the root cause is elsewhere
  (e.g. a short server-side access-token TTL with no refresh, or the refresh
  endpoint itself failing); capture the network trace (which request 401s, whether
  `/refresh` is called and its response) and report instead of guessing.

## Maintenance notes

- This is the auth interceptor — any future change here must preserve: single
  in-flight refresh (dedup), all concurrent waiters retrying, and logout ONLY on a
  genuinely failed refresh or a second 401 after retry.
- A reviewer should re-run the rapid-navigation smoke and confirm a forced-expired
  token still recovers (one refresh, no logout) rather than just trusting the diff.
