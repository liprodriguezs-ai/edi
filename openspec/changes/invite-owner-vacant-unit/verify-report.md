# Verification Report: invite-owner-vacant-unit

- **Change**: invite-owner-vacant-unit
- **Project**: residia-monorepo
- **Mode**: Standard (strict_tdd false) · Artifact store: hybrid · Delivery: single-PR with approved size:exception
- **Verdict**: **PASS WITH WARNINGS** (0 CRITICAL · 2 WARNING · 2 SUGGESTION)

## Completeness

| Dimension | Result |
|---|---|
| Tasks (24) marked complete | Yes — all [x], match code state |
| Files changed = 6 declared | Yes (backend 2, frontend 4); unrelated parallel-session WIP left untouched |
| Spec requirements covered | 6/6 requirements; 2 required scenarios lack a dedicated passing test (see WARNINGs) |
| Design D1–D6 conformance | Full |

## Build / Tests Evidence

| Command | Result |
|---|---|
| `php artisan test --filter=ResidentInvitation` (host php, single process) | 25 passed, 108 assertions, 137.33s (11 Owner + 14 regression) |
| `pnpm typecheck` (tsc -p tsconfig.app.json) | Clean, 0 errors |
| `npx vitest run src/modules/residentInvitation/schema.test.ts` (isolated) | 5 passed (5) |
| Full frontend suite (`pnpm test`) | Flaky timeout failures in UNRELATED modules only (reservation/access/package/complaint/communityProject/configuration); non-deterministic (3, 15, 6 failures across 3 runs); residentInvitation never among them |

## Spec Scenario → Evidence Matrix

| Requirement / Scenario | Evidence | Status |
|---|---|---|
| OWNER gated — Admin invites owner to vacant unit | test_admin_invites_owner_to_vacant_unit_succeeds (201, pending OWNER) | PASS |
| OWNER gated — Resident self-service attempts OWNER | test_self_service_owner_invite_via_active_owner_path_is_rejected (422, none) | PASS |
| OWNER gated — Unit already has active owner | test_admin_owner_invite_to_occupied_unit_is_rejected (422, owner untouched) | PASS |
| One pending — Second OWNER invite blocked | test_second_pending_owner_invite_different_email_is_rejected (422, A untouched) | PASS |
| One pending — Revoked/expired invite frees the unit | Implemented in unitHasPendingOwnerInvitation predicate (whereNull revoked_at, expires_at null-or-future); NO dedicated test | WARNING (UNTESTED) |
| One pending — Different-relationship pending blocks OWNER | test_owner_invite_blocked_by_pending_invitation_of_different_relationship_same_email (422, kept) | PASS |
| Accept routes changeOwner — Clean accept | test_owner_accept_happy_path_promotes_via_change_owner (is_billing_owner=true, 1 notification, accepted_at set) | PASS |
| Accept routes changeOwner — Stale accept | test_owner_stale_accept_via_public_flow (409) + via_confirm_flow (400); accepted_at null, no owner row, user rolled back | PASS |
| relationshipName — Mail context OWNER = 'propietario' | Implemented in relationshipName() match arm; NO dedicated assertion of relationship_name='propietario' | WARNING (UNTESTED) |
| Frontend gate — Admin on vacant unit sees OWNER | schema.test "includes OWNER when canManageInvitations && vacant" + UnitDetailPage wiring (code inspection) | PASS |
| Frontend gate — Self-service never offers OWNER | schema.test "excludes OWNER when undefined" + MyUnitPage omits prop (code inspection) | PASS |
| Frontend gate — Unit already has owner | schema.test "excludes OWNER when hasActiveOwner true" | PASS |
| BILLING_CONTACT available in invite UI | schema.test "always includes BILLING_CONTACT in base set" | PASS |

## Design Conformance (D1–D6)

| Decision | Verified |
|---|---|
| D1 — OWNER guards in inviteToUnit as ValidationException(unit_relationship_id) 422, order admin→active-owner→pending-OWNER→other-relationship, placed after INVITABLE check before checkEmail | Yes |
| D2 — accept calls changeOwner nested in existing accept transaction | Yes |
| D3 — stale accept → InvalidArgumentException(msg,409), token survives via outer rollback; per-flow 409 (public) / 400 (confirm) | Yes (both tested) |
| D4 — Unit::whereKey()->lockForUpdate()->first() before vacancy re-check | Yes |
| D5 — pending-OWNER guard excludes invitee's own email (email != $email); same-email rotates | Yes (tested) |
| D6 — frontend OWNER iff usePermissions('create.resident_invitation') && hasActiveOwner===false, pure helper; MyUnitPage untouched | Yes |
| No notification duplication — OWNER/create branches mutually exclusive; exactly one changeOwner notification | Yes (asserted in happy-path test) |

## Conventions

- English identifiers: PASS (assertOwnerInviteAllowed, unitHasPendingOwnerInvitation, unitHasPendingInvitationForOtherRelationship).
- Mexican-Spanish (tú-form) messages: PASS ("Usa \"Cambiar propietario\"", "Revócala", "Contacta a la administración de tu condominio").
- UnitRelationship constants, no literal UUIDs in changed code: PASS (UnitRelationship::OWNER / UNIT_RELATIONSHIP.OWNER throughout).
- No edits outside the 6 declared files: PASS (backend diff = exactly the 2 files; frontend residentInvitation+unit = exactly the 4 files; chargeConcept/reservation diffs are parallel-session WIP, out of scope).

## Issues

### CRITICAL
None.

### WARNING
1. Spec scenario "Revoked/expired invite frees the unit" has no dedicated passing test. Behavior is implemented in the `unitHasPendingOwnerInvitation` predicate (`whereNull('revoked_at')` + `expires_at` null-or-future) and the adjacent different-email/same-email tests exercise the same predicate, but the revoke/expire-frees path is not isolated. Low risk (permissive path). Recommend adding one test before archive.
2. Spec scenario "Mail context for an OWNER invite → relationship_name='propietario'" has no dedicated assertion. Implemented in `relationshipName()`; pure display string in email copy, very low risk.

### SUGGESTION
1. Frontend requirement scenarios are covered at the pure-helper level (per design D6, deliberately chosen over component-mount tests). The actual `UnitDetailPage`/`MyUnitPage` prop wiring is verified by code inspection only. Acceptable per D6.
2. Size: 657 changed lines vs 400 budget (approved size:exception). Overage driven by the 451-line test file; report only, non-blocking.

## Notes
- Full frontend suite is environmentally flaky (jsdom render timeouts in unrelated modules under parallel WIP); does not reflect on this change. residentInvitation tests are green in isolation.
