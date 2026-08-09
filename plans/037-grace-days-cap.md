# Plan 037: Align `grace_days` frontend cap with the backend (60 → 365)

> **Executor instructions**: Follow step by step, run every verification command,
> honor STOP conditions, update this plan's row in `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/chargeConcept/schema.ts`
> On mismatch with the "Current state" excerpt, treat as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: frontend `0887d5a`, 2026-06-20

## Why this matters

The charge-concept form rejects `grace_days` over 60 with a client-side Zod error,
but the backend accepts up to 365 (`ChargeConceptCreateRequest`/`UpdateRequest`:
`'grace_days' => ['nullable', 'integer', 'min:0', 'max:365']`). A user who needs
90 grace days (e.g. quarterly billing) cannot enter it — the form silently blocks
a value the server would happily accept. It is a one-character fix to remove the
artificial frontend ceiling.

## Current state

- `frontend/src/modules/chargeConcept/schema.ts:68`
  ```ts
  grace_days: z.number().int().min(0).max(60).nullable(),
  ```
- Backend (authoritative cap), `backend/Modules/Collections/app/Http/Requests/ChargeConceptCreateRequest.php:38`
  and `ChargeConceptUpdateRequest.php` (same line):
  ```php
  'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
  ```

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Typecheck+build | `pnpm -C frontend build` | exit 0 |
| Lint | `pnpm -C frontend lint` | no new errors |

## Scope

**In scope:** `frontend/src/modules/chargeConcept/schema.ts` (the `grace_days` rule only).

**Out of scope:** the backend requests (already correct at 365); any other field in the schema.

## Git workflow

Submodule `frontend/`. Branch `advisor/037-grace-days-cap`. Conventional commit, no AI attribution. `--no-gpg-sign --no-verify` if signing/hooks fail.

## Steps

### Step 1: Raise the frontend cap to 365

Change `.max(60)` to `.max(365)` on the `grace_days` rule in
`chargeConcept/schema.ts:68`. Nothing else.

**Verify**: `grep -n "grace_days: z.number().int().min(0).max(365).nullable()" frontend/src/modules/chargeConcept/schema.ts` → one match.

### Step 2: Build + lint

**Verify**: `pnpm -C frontend build` exit 0; `pnpm -C frontend lint` no new errors.

## Test plan

- If `chargeConcept/` has a schema test, add a case asserting `grace_days: 90`
  parses successfully and `grace_days: 366` fails. Otherwise this is covered by
  build + manual entry of 90 in the form (saves without a client error).

## Done criteria

- [ ] `grace_days` rule reads `.max(365)`
- [ ] `pnpm -C frontend build` exits 0
- [ ] Only `chargeConcept/schema.ts` modified (`git -C frontend status`)
- [ ] `plans/README.md` row updated

## STOP conditions

- The `grace_days` line does not match the excerpt (drift) — compare and report.
- The backend cap is no longer 365 (re-check `ChargeConceptCreateRequest.php`) —
  if it changed, match whatever the backend now enforces, and report the new value.

## Maintenance notes

- Keep this cap a mirror of the backend rule. If the backend cap changes, this
  line must change with it — they are a contract pair.
