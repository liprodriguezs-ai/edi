# Design: Invite Owner for Vacant Unit

> Phase: DESIGN. Store: hybrid (this file + Engram `sdd/invite-owner-vacant-unit/design`).

## Technical Approach

All backend changes stay inside `ResidentInvitationService`: admit OWNER into `INVITABLE_RELATIONSHIPS` behind three service-level invite guards, and route the OWNER accept through `UnitUserService::changeOwner` inside the existing accept transaction. Controller (`authorizeUnit`), routes, `UnitUserService`, and migrations are untouched. Frontend adds a tri-state `hasActiveOwner` prop plus a pure option-filter helper; backend remains the authority.

## Architecture Decisions

| # | Decision | Alternative rejected | Rationale |
|---|---|---|---|
| D1 | OWNER invite guards live in `inviteToUnit`, thrown as `ValidationException::withMessages(['unit_relationship_id' => …])` (422) | Controller check in `store()` → `AuthorizationException` (403) | Relationship-conditional rules already live in the service (INVITABLE list, renter-requires-owner, both 422 on the same field); single enforcement point for any caller; frontend already renders 422 field errors via `formatApiErrorMessage`. `authorizeUnit` stays coarse (may this actor invite to this unit at all). |
| D2 | Accept calls `changeOwner` nested in the existing transactions | Inline owner creation | Keeps `changeOwner` the single owner-mutation primitive (billing takeover, invariants, notification). Nesting = third savepoint level, same depth as today's `unitUserService->create` path (outer accept tx → `acceptToUnit` tx → service tx); Laravel savepoints, existing style. |
| D3 | Stale accept → `\InvalidArgumentException(msg, 409)`; token survives via outer rollback | Consume token / auto-revoke | 409 matches accept-path precedent (409 at line ~213). `accepted_at` is stamped by the OUTER `DB::transaction` in `UserInvitationService::acceptInvitation` / `acceptInvitationWithAccount`; the exception rolls everything back (including a just-created `User`) → invitation stays pending with zero extra code. |
| D4 | OWNER accept branch first takes `Unit::whereKey($unit->id)->lockForUpdate()->first()` | Rely on re-check only | `lockForUpdate` over an empty `unit_user` result set locks nothing under Postgres READ COMMITTED, so two simultaneous accepts could both pass the vacancy re-check. Locking the unit row serializes them; second sees the committed owner → 409. Two lines, in-scope. |
| D5 | Pending-OWNER guard excludes the invitee's own email (exact match) | Block all pending OWNER invitations | Same-email re-invite hits `createAndSend`'s exact-match dedup and ROTATES the row (no second invitation) — must stay allowed. Exact match deliberately mirrors the dedup predicate so guard and engine agree. |
| D6 | Frontend: OWNER option iff `usePermissions('create.resident_invitation')` AND `hasActiveOwner === false`; logic in a pure `invitableRelationshipIds()` helper | Component-mount Vitest test | Permission check inside the modal keeps the gate self-contained across both mount sites; pure helper is Vitest-testable without providers (schema.test.ts precedent in unit module). `usePermissions` already honors `*`. |

## Interfaces / Contracts

`ResidentInvitationService` (only backend file changed):

- `INVITABLE_RELATIONSHIPS`: add `UnitRelationship::OWNER`; update comment.
- `inviteToUnit`: OWNER guard block immediately after the INVITABLE membership check (authorization before state checks; an existing resident of the unit still falls through to the `already_in_this_unit` 422 — existing users are promoted via "Cambiar propietario", not invitations):
  1. `! ($inviter->tokenCan('create.resident_invitation') || $inviter->tokenCan('*'))` → «Solo la administración puede invitar a un propietario.»
  2. `$this->unitHasActiveOwner($unit)` → «Esta unidad ya tiene un propietario activo. Usa "Cambiar propietario" para reemplazarlo.»
  3. `$this->unitHasPendingOwnerInvitation($unit, $email)` → «Ya hay una invitación de propietario pendiente para esta unidad. Revócala o espera a que venza.»
- New `private unitHasPendingOwnerInvitation(Unit $unit, string $email): bool` — `user_invitation` is CENTRAL (no TenantScope), so filter manually like the controller does: `inviteable_type = Unit::class`, `inviteable_id = $unit->id`, `tenant_id = tenant()->getTenantKey()`, `unit_relationship_id = UnitRelationship::OWNER`, `accepted_at IS NULL`, `revoked_at IS NULL`, `expires_at NULL or > now()`, `email != $email` → `exists()`.
- `acceptToUnit` — replace the membership write with:

```php
if ($relationshipId === UnitRelationship::OWNER) {
    Unit::whereKey($unit->id)->lockForUpdate()->first(); // D4 mutex
    if ($this->unitHasActiveOwner($unit)) {
        throw new \InvalidArgumentException(
            __('La unidad ya tiene un propietario activo. Contacta a la administración de tu condominio.'), 409);
    }
    $this->unitUserService->changeOwner($unit, ['user_id' => $resolved->id]);
} elseif (! $alreadyActive) {
    $this->unitUserService->create([/* unchanged */]);
}
```

  OWNER deliberately bypasses the `alreadyActive` skip: an acceptor who gained a non-owner membership meanwhile is still promoted; that row stays active (billing flag transfers — documented `changeOwner` takeover). Role logic untouched → OWNER receives `SLUG_RESIDENT`.
- `relationshipName()`: add `UnitRelationship::OWNER => 'propietario'` (email copy; the `.tsx` template already interpolates `relationshipName` — no email change).

**No notification duplication**: invite email sent once by `createAndSend` (SystemMailer). On accept the branches are mutually exclusive — OWNER fires only `changeOwner`'s «Eres el nuevo propietario…» (previous-owner notice skipped: `$previousUserIds === []` on a vacant unit); `create()`'s «Asignación a unidad» never runs for OWNER.

Frontend:

```ts
// residentInvitation/schema.ts
export const INVITABLE_RELATIONSHIPS = new Set([FAMILY_RESIDENT, RENTER, CO_OWNER, BILLING_CONTACT]) // +BILLING_CONTACT
export function invitableRelationshipIds(opts: {
  canManageInvitations: boolean
  hasActiveOwner?: boolean
}): ReadonlySet<string> // base set, +OWNER iff canManageInvitations && hasActiveOwner === false
```

- `InviteResidentModal`: new prop `hasActiveOwner?: boolean` — tri-state: `false` = confirmed vacant (OWNER offered if permitted); `true`/`undefined` = hidden. Filter `relationshipOptions` through the helper.
- `UnitDetailPage` (`useUnitDetailPage`): take `isSuccess` from the existing people query; pass `hasActiveOwner={peopleLoaded ? currentOwner != null : undefined}` — zero new requests, no OWNER flash while loading.
- `MyUnitPage`/`MyUnitCard`: untouched — prop omitted → OWNER never shown in self-service.

## Data Flow (accept)

    token → UserInvitationService.accept* ─ TX1 (stamps accepted_at)
              └→ acceptToUnit ─ TX2/savepoint (user, tenant link, role, profile)
                   └→ OWNER? lock unit → re-check vacant → changeOwner ─ TX3/savepoint
    stale → throw 409 → TX3+TX2+TX1 roll back → token unconsumed, user creation undone

## File Changes

| File | Action | Description |
|---|---|---|
| `backend/Modules/Housing/app/Services/ResidentInvitationService.php` | Modify | Guards, pending-OWNER query, OWNER accept branch, relationshipName |
| `backend/tests/Feature/Housing/ResidentInvitationOwnerTest.php` | Create | Feature coverage below |
| `frontend/src/modules/residentInvitation/schema.ts` | Modify | +BILLING_CONTACT, `invitableRelationshipIds()`, comment rewrite |
| `frontend/src/modules/residentInvitation/InviteResidentModal.tsx` | Modify | `hasActiveOwner` prop + permission-gated options |
| `frontend/src/modules/unit/UnitDetailPage.tsx` | Modify | Pass tri-state `hasActiveOwner` |
| `frontend/src/modules/residentInvitation/schema.test.ts` | Create | Helper filtering tests |

## Testing Strategy

PHPUnit (host php, single process): 1) admin + vacant → 201 pending OWNER invitation; 2) co-owner self-service (passes `authorizeUnit` via `isActiveOwner`, unit has no OWNER) → 422 permission message; 3) admin + occupied → 422; 4) second pending OWNER other email → 422, same email → rotates; 5) pending OWNER does not block FAMILY_RESIDENT invite; 6) accept happy path → active OWNER `unit_user`, `is_billing_owner = true`, `accepted_at` set, exactly one changeOwner notification (NotificationWiringTest pattern); 7) stale accept → 409, `accepted_at` null, no owner row, created user rolled back; 8) accept with pre-existing family membership → promoted, both rows active.

Vitest: `invitableRelationshipIds` — base includes BILLING_CONTACT; OWNER excluded when no permission / `hasActiveOwner` true / undefined; included with permission + `false`.

Budget forecast: ~325 changed lines (tests dominate) — fits single PR; sdd-tasks re-forecasts.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration. Code-only revert in both submodules; restored guard re-blocks pending OWNER invitations (admin revokes them).

## Open Questions

None — auto mode; proposal decisions 1–3 honored.
