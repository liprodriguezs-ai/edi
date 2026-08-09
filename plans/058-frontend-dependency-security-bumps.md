# Plan 058: Patch the HIGH-severity axios and react-router-dom CVEs (direct frontend deps)

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `cd frontend && pnpm audit --prod` — confirm the axios/react-router advisories are still
> present before acting.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED (touches the app's HTTP client and router — the most load-bearing libs)
- **Depends on**: none
- **Category**: security (dependencies)
- **Planned at**: frontend `8f3675b`, 2026-07-01

## Why this matters

`pnpm audit --prod` on the frontend reports 53 vulnerabilities including 1 critical / 25
high. Two of the affected packages are DIRECT dependencies on load-bearing paths:

- **axios `1.13.6`** (the sole HTTP client for every API call — bearer token, `X-Tenant-Id`,
  refresh-on-401 interceptors) sits in vulnerable ranges (`<1.16.0`, `<1.15.2`) for
  prototype-pollution credential theft / request hijacking, ReDoS via cookie injection,
  and proxy-header leaks.
- **react-router-dom `^7.13.1`** (drives all client routing) sits in `>=7.0.0 <=7.14.1`
  for an unauth-RCE-class bug (vendored turbo-stream v2 arbitrary constructor invocation
  via TYPE_ERROR deserialization) plus HIGH DoS/XSS advisories.

(The audit also flags `protobufjs` CRITICAL, but it only enters via
`firebase > @firebase/firestore > @grpc/grpc-js`, and the app imports only `firebase/app`
+ `firebase/messaging` — no firestore — so it is likely dead weight not shipped in the
bundle. Deprioritized; verify with a bundle check if pursued.)

## Current state

- `frontend/package.json`: `"axios": "1.13.6"` (or `^1.13.x` — confirm the exact line),
  `"react-router-dom": "^7.13.1"` (line ≈83), `"vite": "^8.0.0-beta.13"` (line ≈124 — beta,
  see the index's deferred note; NOT part of this plan).
- Frontend has a green vitest suite: 48 files / 390 tests (measured this cycle).
- Frontend has NO CI running audit/lint/tests (see plan 063) — so this bump is validated
  locally + by the vitest suite.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Audit | `cd frontend && pnpm audit --prod` | after fix: axios + react-router HIGHs gone |
| Update | `cd frontend && pnpm add axios@^1.16.0 react-router-dom@^7.15.0` | resolves to patched versions |
| Typecheck | `cd frontend && pnpm exec tsc --noEmit` | exit 0 |
| Tests | `cd frontend && pnpm test -- --run` | 390 tests pass |
| Build (optional) | `cd frontend && pnpm build` | exit 0 |

## Scope

**In scope**:
- `frontend/package.json` + `frontend/pnpm-lock.yaml` — bump `axios` and `react-router-dom`
  to the first patched versions (verify current patched floor against the audit output).

**Out of scope**:
- `vite` beta (separate deferred item).
- Application code — unless a bump introduces a breaking API change, in which case STOP.
- `protobufjs`/firebase (deprioritized; separate investigation).

## Steps

### Step 1: Confirm advisories and patched floors

`pnpm audit --prod`; note the exact patched versions the advisories recommend for axios
and react-router-dom.

**Verify**: you have the target versions.

### Step 2: Bump

`pnpm add axios@^1.16.0 react-router-dom@^7.15.0` (adjust to the confirmed patched floor).

**Verify**: `pnpm audit --prod` no longer lists the axios/react-router HIGH advisories.

### Step 3: Prove auth + routing still work

`pnpm exec tsc --noEmit` (react-router 7 minor bumps occasionally touch types), then
`pnpm test -- --run` (390 tests, incl. apiClient/auth tests). Manually smoke-test login →
navigate → refresh-on-401 if a dev server is available (the refresh-token interceptor is
the highest-risk surface for an axios bump).

**Verify**: `tsc --noEmit` exit 0; `pnpm test -- --run` all pass.

## Done criteria

- [ ] axios ≥ patched floor, react-router-dom ≥ patched floor
- [ ] `pnpm audit --prod` no longer lists those HIGH advisories
- [ ] `pnpm exec tsc --noEmit` exit 0
- [ ] `pnpm test -- --run` 390 tests pass
- [ ] Only `package.json` + `pnpm-lock.yaml` changed
- [ ] `plans/README.md` row updated

## STOP conditions

- A bump breaks types or tests in app code (react-router 7 loader/type changes, axios
  interceptor signature) — STOP and report; the code migration is a separate plan.
- The patched react-router version is a MAJOR bump (8.x) — do not jump majors here; report.
- `pnpm audit` still flags the packages after the bump (no clean version yet) — report the
  residual and hold.

## Maintenance notes

- Fold `pnpm audit --prod` into frontend CI once CI exists (plan 063) so future HIGHs fail
  the build.
- Reviewer: scrutinize the apiClient refresh-token flow after the axios bump — it's the
  most fragile consumer.
- `protobufjs`/firebase and the `vite` beta pin are tracked separately in the index.
