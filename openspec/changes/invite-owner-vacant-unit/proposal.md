# Proposal: Invite Owner for Vacant Unit

> Phase: PROPOSE. Store: hybrid (this file + Engram `sdd/invite-owner-vacant-unit/proposal`). Auto mode — open questions resolved below.

## Intent

Admins have no UI path to onboard a NEW owner: the invitation flow deliberately excludes OWNER, "Asociar persona"/"Cambiar propietario" only pick existing users, and `/user` is super-admin-only. Fix: allow OWNER in the resident-invitation flow, admin-only, only while the unit has no active owner, and route the accept through `UnitUserService::changeOwner` so owner invariants hold.

## Scope

### In Scope
- `ResidentInvitationService::inviteToUnit` OWNER guards: inviter has `create.resident_invitation` or `*` (never the `isActiveOwner` self-service path); unit has zero active owners (`unitHasActiveOwner()` exists); no other pending OWNER invitation for the unit.
- `acceptToUnit`: OWNER branch re-checks "no active owner" inside the existing transaction, then calls `changeOwner` (single-owner, billing takeover, notifications) instead of plain create. Stale (owner appeared meanwhile) → 4xx with Mexican-Spanish message; token NOT consumed, invitation stays pending for admin follow-up.
- `relationshipName()`: add `OWNER => 'propietario'` (email copy today falls back to "residente").
- Frontend `schema.ts` sets: add OWNER (gated) and BILLING_CONTACT (ungated — aligns with backend, which already accepts it).
- `InviteResidentModal`: optional `hasActiveOwner` prop; OWNER shown iff `create.resident_invitation` permission AND `hasActiveOwner === false`. Prop omitted → hidden (modal is also mounted by resident self-service `MyUnitCard`).
- `UnitDetailPage`: pass `hasActiveOwner` from already-loaded `currentOwner` — zero new requests.
- Tests: new PHPUnit feature file (service has none today) + Vitest option filtering.

### Out of Scope
- DataImport owner path (`RowImporter`) — untouched.
- Auto-revoking pending OWNER invitations on manual owner assignment (accept-time rejection covers correctness).
- Any change to `UnitUserService`, `checkEmail` contract, schemas/migrations, MyUnitPage.

## Capabilities

> `openspec/specs/` does not exist yet; sdd-spec creates the spec fresh.

### New Capabilities
- None.

### Modified Capabilities
- `resident-invitation`: OWNER conditionally invitable (admin-only, vacant unit); accept upholds owner invariants via changeOwner.

## Approach

Reuse the invitation engine; keep `changeOwner` the single owner-mutation primitive. Concurrency: one-pending-OWNER rule serializes invites; in-transaction re-check catches a manual assignment racing an accept; manual `changeOwner` after an accepted invite keeps its documented dethroning semantics. OWNER accepts get Resident role (current non-renter logic) and only changeOwner's notification fires — no duplicates. Backend stays the authority; frontend gating is UX.

## Affected Areas

| Area | Impact |
|---|---|
| `backend/Modules/Housing/app/Services/ResidentInvitationService.php` | Modified — guards, accept routing, relationshipName |
| `frontend/src/modules/residentInvitation/schema.ts` | Modified — relationship sets |
| `frontend/src/modules/residentInvitation/InviteResidentModal.tsx` | Modified — gated OWNER option + prop |
| `frontend/src/modules/unit/UnitDetailPage.tsx` | Modified — pass `hasActiveOwner` |
| `backend/tests/Feature/Housing/` | New — ResidentInvitationOwnerTest |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Two concurrent OWNER accepts → two owners | Low | One-pending-OWNER invite rule + in-transaction re-check |
| OWNER leaks into self-service modal | Med | Double gate (permission + prop default-hidden) + backend guard + tests |
| Acceptor gained a non-owner membership between invite/accept | Low | Spec scenario: proceed via changeOwner; existing association untouched |
| Tests push the 400-line review budget | Med | sdd-tasks forecasts; slice if needed |

## Rollback Plan

Code-only (no migrations): revert commits in both submodules. Any pending OWNER invitation becomes un-acceptable again (guard restored) — admin revokes it.

## Dependencies

None new — existing UserInvitation engine, `changeOwner`, central `UnitRelationship` UUIDs.

## Success Criteria

- [ ] Admin invites owner to a vacant unit end-to-end; accept creates active OWNER with `is_billing_owner = true` via changeOwner, notifications fire once.
- [ ] Resident self-service never sees nor can use OWNER (frontend and backend enforced).
- [ ] Unit with active owner: OWNER not offered; backend rejects invite and stale accept with clear Spanish message, token not consumed.
- [ ] BILLING_CONTACT selectable in UI (backend parity).
- [ ] New PHPUnit feature tests + Vitest filtering tests green.

## Resolved decisions (auto mode — flag if wrong)

1. Stale OWNER invitation at accept: reject and keep pending (not auto-revoke) — avoids cross-module coupling.
2. BILLING_CONTACT shown to all inviters — backend already allows owners to send it.
3. Max one pending OWNER invitation per unit — blocks a second until revoked/expired.
