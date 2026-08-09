# Plan 041: Make the remote-approval flow reachable by residents (§14.4.5)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. If a
> "STOP condition" occurs, stop and report. When done, update the status row in
> `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access/VisitorListPage.tsx`
> If it changed, compare the "Current state" excerpt to the live code first.

## Status

- **Priority**: P1
- **Effort**: M
- **Depends on**: plans/040 (soft — 040 renames `status`→`visitor_status`; do 040 first so the status read here is already snake_case)
- **Category**: bug
- **Planned at**: monorepo `b2c06dc`, frontend submodule `0887d5a`, 2026-06-21

## Why this matters

The remote-approval flow (§14.4.5, Flujo B) is the core of "visita imprevista": a
guard registers a walk-in, the unit's residents get a push, and a resident taps
**Aprobar / Rechazar**. Today those buttons are gated on the `update.visitor`
permission (`VisitorListPage.tsx:30,76,239`), but the backend authorizes
approve/reject by **active unit membership**, NOT by `update.visitor`
(`VisitorController::authorizeUnitMembership`). Per the role seeder, residents have
only `read.visitor` + `create.visitor` and security has only `read.visitor` — so
**nobody who should approve ever sees the buttons**. Admins (the only role with
`update.visitor`) see them but get 403 because they aren't unit members.

The flow is therefore unreachable by its intended actor: the entire §14.4.5 / Flujo B
is dead UI. Worse, the buttons currently render for EVERY visitor row regardless of
status (no `PENDING_APPROVAL` filter), so even when wired they'd appear on used /
expired visitors. This plan gates the buttons on unit membership and restricts them
to pending-approval visitors, so a resident landing on `/visitor` can actually act.

## Current state

`frontend/src/modules/access/VisitorListPage.tsx`:
```tsx
const PERMISSIONS = { canUpdate: 'update.visitor' } as const   // line 29-31
// ...
const permissions = useMultiplePermissions(PERMISSIONS)         // line 115
// ...
{canUpdate && (                                                 // line 76 (inside VisitorActions)
  <> <Button …>Aprobar</Button> <Button …>Rechazar</Button> </>
)}
// passed at lines 239 & 260: canUpdate={permissions.canUpdate}
```

Backend authorizer (`backend/Modules/Access/app/Http/Controllers/VisitorController.php:158-170`):
```php
private function authorizeUnitMembership(Request $request, Visitor $visitor): void {
    $isMember = UnitUser::where('unit_id', $visitor->unit_id)
        ->where('user_id', $userId)->whereNull('end_date')->exists();
    if (! $isMember) throw new AuthorizationException('Unauthorized action.');
}
```

Visitor statuses (`frontend/src/modules/access/schema.ts:10-18`): the pending-approval
state is `VISITOR_STATUS.PENDING_APPROVAL`.

**How the frontend knows the user's units**: inspect the session store first —
`frontend/src/shared/auth/sessionStore.ts` / `useSession.ts` / `Session.ts`. Find
whether the authenticated profile exposes the user's `unit_user` memberships or unit
IDs. If it does, use that. If it does NOT, see Step 2's fallback.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Frontend typecheck | `cd frontend && pnpm build` | exit 0 |
| Frontend lint | `cd frontend && pnpm lint` | no new errors |
| Access tests | `cd frontend && pnpm test src/modules/access` | all pass |

## Scope

**In scope**:
- `frontend/src/modules/access/VisitorListPage.tsx`
- `frontend/src/modules/access/schema.ts` (only if a helper type is needed)
- Possibly a read of `frontend/src/shared/auth/useSession.ts` / `sessionStore.ts` to source the user's unit IDs (read-only unless the session genuinely lacks unit data — then STOP, see below)

**Out of scope**:
- Backend authorization (already correct — membership-based).
- Push-notification deep-linking (a separate, larger piece — see Maintenance notes).
- Any change to `update.visitor` seeding.

## Steps

### Step 1: Determine the membership signal available on the client

Read `frontend/src/shared/auth/useSession.ts`, `sessionStore.ts`, and `Session.ts`.
Establish whether the session profile includes the user's active unit memberships
(e.g. `profile.units`, `unit_user[]`, or unit IDs).

**Verify**: write one sentence in the PR description naming the exact field, or
confirm none exists. (No command — this is discovery.)

### Step 2: Gate Approve/Reject on unit membership + PENDING_APPROVAL status

Replace the `canUpdate` gate on the Approve/Reject buttons with a `canApprove`
condition that is true only when BOTH:
1. `visitor.visitor_status_id === VISITOR_STATUS.PENDING_APPROVAL`, AND
2. the current user is an active member of `visitor.unit_id` (from Step 1's field),
   OR the user holds a broad staff ability that the backend would also accept.

If Step 1 found a units field on the session, compute membership client-side. If the
session does NOT expose units, **fallback**: keep the buttons gated to
`PENDING_APPROVAL` AND show them whenever the visitor row is visible to this user
(the backend still enforces membership and returns a clear 422/403 the existing
`onError` toast surfaces) — i.e. rely on backend enforcement for correctness and use
the status filter to avoid showing them on non-pending rows. Prefer the client-side
membership check if the data exists; the fallback must never be the excuse to drop the
`PENDING_APPROVAL` filter.

Leave the existing "Ver QR" and "Cancelar QR" buttons (and their gates) untouched.

**Verify**: `cd frontend && pnpm build` → exit 0. Manually confirm in code that the
Approve/Reject branch no longer reads `permissions.canUpdate` and now reads the
pending-approval status.

### Step 3: Remove the now-dead `update.visitor` permission constant if unused

If `permissions.canUpdate` is no longer referenced anywhere in the file after Step 2,
remove the `PERMISSIONS`/`useMultiplePermissions` lines to avoid a dead permission
fetch. If it IS still used elsewhere, leave it.

**Verify**: `cd frontend && pnpm lint src/modules/access/VisitorListPage.tsx` → no
`no-unused-vars` error for `permissions`/`canUpdate`.

## Test plan

- Add a characterization test (model after `frontend/src/modules/access/components/ScanQrModal.test.tsx`)
  in a new `frontend/src/modules/access/VisitorListPage.test.tsx` OR extend an existing
  test, asserting: a `PENDING_APPROVAL` visitor in the user's unit renders the "Aprobar"
  button; a `USED` visitor does not; (if membership data is available) a visitor in
  another unit does not render it.
- If the session-units shape makes a unit test impractical, document that and rely on
  the build gate + a manual smoke note.
- Verification: `cd frontend && pnpm test src/modules/access` → all pass.

## Done criteria

- [ ] `cd frontend && pnpm build` exits 0
- [ ] Approve/Reject buttons no longer gated on `update.visitor`
- [ ] Approve/Reject only render for `VISITOR_STATUS.PENDING_APPROVAL` visitors
- [ ] `cd frontend && pnpm test src/modules/access` passes (incl. any new test)
- [ ] No files outside scope modified
- [ ] `plans/README.md` status row updated

## STOP conditions

- The session/auth store exposes NO way to know the user's units AND product wants a
  strict client-side membership gate (not backend-enforced fallback) → STOP and ask
  whether to (a) accept the backend-enforced fallback, or (b) add a `mine()`-style
  endpoint / extend the session payload (out of this plan's scope).
- Removing the `canUpdate` constant breaks another button in the file → revert Step 3.

## Maintenance notes

- **Deferred (larger piece)**: there is no push-notification deep-link that opens the
  approval directly when the resident taps the FCM push (§14.4.5 screen). This plan
  makes the action reachable from the `/visitor` list; a future plan should add a
  `PendingApprovalModal` opened via deep-link (`/visitor?pending_approval=<id>`).
- A reviewer should confirm the backend still enforces membership (defense in depth) —
  the client gate is UX, not security.
- If the session payload is extended to carry unit memberships, prefer that as the
  single source for "is this my unit" checks across modules.
