# Tasks: Invite Owner for Vacant Unit

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~345–365 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | single PR |
| Delivery strategy | single-pr |
| Chain strategy | size-exception |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

Re-forecast vs design's ~325: +1 guard method (`unitHasPendingOwnerInvitation`, ~15 lines) and +1 dedup-rotation test (~20 lines) added per directive 2. `ResidentInvitationOwnerTest.php` now ~9 cases (was 8) plus the dedup-rotation case ≈ 210–230 lines; `ResidentInvitationService.php` diff ≈ 55–65 lines; frontend (`schema.ts` + `InviteResidentModal.tsx` + `UnitDetailPage.tsx` + `schema.test.ts`) ≈ 60–70 lines; spec append ≈ 12 lines. Single file (`ResidentInvitationService.php`) stays well under budget alone; the aggregate is Medium risk only because of test volume, which is low-complexity boilerplate. Recommend `size:exception` (single-pr strategy) rather than splitting — service guards, accept branch, and their tests are one cohesive, hard-to-review-in-isolation unit.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Backend guards + accept routing + tests | PR 1 (whole change) | `php artisan test --filter=ResidentInvitationOwnerTest` | `php artisan test tests/Feature/Housing/ResidentInvitationOwnerTest.php` (host php, single process) | Revert `ResidentInvitationService.php` + delete `ResidentInvitationOwnerTest.php`; guard restored, no migration to undo |
| 2 | Frontend gating + tests | Same PR | `pnpm test schema.test.ts` (frontend/) | `pnpm test residentInvitation` | Revert the 3 frontend files; OWNER stays hidden everywhere (safe default) |

## Phase 1: Backend — Invite guards (`ResidentInvitationService.php`)

- [x] 1.1 Add `UnitRelationship::OWNER` to `INVITABLE_RELATIONSHIPS` (line ~30–35); rewrite the doc comment above the constant (line ~26–29) to state OWNER is now admin-gated, not excluded.
- [x] 1.2 Add private `unitHasPendingOwnerInvitation(Unit $unit, string $email): bool` near `unitHasActiveOwner()` (after line ~279): query `UserInvitation` with `inviteable_type = Unit::class`, `inviteable_id = $unit->id`, `tenant_id = tenant()->getTenantKey()`, `unit_relationship_id = UnitRelationship::OWNER`, `whereNull('accepted_at')`, `whereNull('revoked_at')`, `expires_at` null-or-future, `email != $email` (D5 exact-match exclusion) → `exists()`.
- [x] 1.3 In `inviteToUnit` (after the INVITABLE check at line ~127–131, before the `checkEmail` call), add the OWNER guard block — three checks in order per design D1:
  1. `$unitRelationshipId === UnitRelationship::OWNER && ! ($inviter->tokenCan('create.resident_invitation') || $inviter->tokenCan('*'))` → `ValidationException::withMessages(['unit_relationship_id' => ['Solo la administración puede invitar a un propietario.']])`.
  2. else `$unitRelationshipId === UnitRelationship::OWNER && $this->unitHasActiveOwner($unit)` → `ValidationException::withMessages(['unit_relationship_id' => ['Esta unidad ya tiene un propietario activo. Usa "Cambiar propietario" para reemplazarlo.']])`.
  3. else `$unitRelationshipId === UnitRelationship::OWNER && $this->unitHasPendingOwnerInvitation($unit, $email)` → `ValidationException::withMessages(['unit_relationship_id' => ['Ya hay una invitación de propietario pendiente para esta unidad. Revócala o espera a que venza.']])`.
- [x] 1.4 [Directive 2] In the same OWNER branch of `inviteToUnit`, reject when a pending invitation for **this exact email + this unit** already exists with a **different** `unit_relationship_id` (any relationship, not just OWNER) — Mexican-Spanish message telling the admin to revoke or resend the existing invitation before switching the relationship. Query: `UserInvitation` matching `inviteable_type/id`, `tenant_id`, `email = $email`, `unit_relationship_id != $unitRelationshipId`, pending (same null/expiry predicate as 1.2) → `exists()` → `ValidationException::withMessages(['unit_relationship_id' => ['Ya existe una invitación pendiente para este correo con otro tipo de residente. Revócala o espera a que se resuelva antes de invitarla como propietario.']])`. Keep this check scoped to the OWNER branch only (does not change existing non-OWNER invite behavior).

## Phase 2: Backend — Accept routing (`ResidentInvitationService.php`)

- [x] 2.1 In `acceptToUnit`'s inner `DB::transaction` closure (replace lines ~245–255), branch on `$relationshipId === UnitRelationship::OWNER`: `Unit::whereKey($unit->id)->lockForUpdate()->first()` (D4 mutex), re-check `$this->unitHasActiveOwner($unit)` → if true, throw `new \InvalidArgumentException(__('La unidad ya tiene un propietario activo. Contacta a la administración de tu condominio.'), 409)`; else call `$this->unitUserService->changeOwner($unit, ['user_id' => $resolved->id])`. Keep the existing `elseif (! $alreadyActive) { $this->unitUserService->create([...]) }` branch unchanged for non-OWNER relationships.
- [x] 2.2 Confirm `$roleSlug` logic at line ~257 is untouched — OWNER falls through to `Role::SLUG_RESIDENT` (only RENTER maps to `SLUG_RENTER`); add no special case.
- [x] 2.3 Add `UnitRelationship::OWNER => 'propietario'` to the `match` in `relationshipName()` (line ~316–325), replacing the `default => 'residente'` fallback for this key.

## Phase 3: Backend — Tests

- [x] 3.1 Create `backend/tests/Feature/Housing/ResidentInvitationOwnerTest.php` (`final class ... extends TestCase`, follow `ResidentInvitationTest.php` conventions: host php, no Sail, `RefreshDatabase` — single test process). Cases (design Testing Strategy, RED-first is not required here since there's no threat matrix, but each case is its own task):
  - [x] 3.1.1 `test_admin_invites_owner_to_vacant_unit_succeeds` — admin (`create.resident_invitation`) + vacant unit → 201, pending OWNER `UserInvitation` created.
  - [x] 3.1.2 `test_self_service_owner_invite_via_active_owner_path_is_rejected` — co-owner authorized only via `isActiveOwner` self-service path, unit has no OWNER → 422 with the "Solo la administración…" message; no invitation created.
  - [x] 3.1.3 `test_admin_owner_invite_to_occupied_unit_is_rejected` — admin + unit with active OWNER → 422 "ya tiene un propietario activo"; existing owner untouched.
  - [x] 3.1.4 `test_second_pending_owner_invite_different_email_is_rejected` — unit has pending OWNER invite for email A; admin invites email B as OWNER → 422; invite A untouched.
  - [x] 3.1.5 `test_second_pending_owner_invite_same_email_rotates_token` — unit has pending OWNER invite for email A; admin re-invites email A as OWNER → 200/201, same invitation row rotates (dedup path, D5) — no 422.
  - [x] 3.1.6 [Directive 2] `test_owner_invite_blocked_by_pending_invitation_of_different_relationship_same_email` — unit has a pending FAMILY_RESIDENT (or RENTER) invite for email A; admin invites email A as OWNER → 422 with the new "otro tipo de residente" message; the pending non-OWNER invitation is untouched (not rotated, not consumed).
  - [x] 3.1.7 `test_pending_owner_invite_does_not_block_family_resident_invite` — unit has pending OWNER invite; admin invites a different email as FAMILY_RESIDENT → succeeds (guard is OWNER-branch-only).
  - [x] 3.1.8 `test_owner_accept_happy_path_promotes_via_change_owner` — accept a pending OWNER invitation on a still-vacant unit → active `unit_user` row with `unit_relationship_id = OWNER`, `is_billing_owner = true`, `accepted_at` set; assert `changeOwner`'s notification fires exactly once (Notification::fake pattern from `UnitOwnerChangeTest.php`) and the plain `create()` "Asignación a unidad" notification does NOT fire.
  - [x] 3.1.9 `test_owner_stale_accept_owner_appeared_meanwhile_is_rejected` — pending OWNER invitation; another owner gets assigned to the unit before accept → accept call returns the correct status per invitee flow (see 3.2), `accepted_at` stays null, no new `unit_user` OWNER row, and if a new `User` would have been created it is rolled back (assert `User::where('email', ...)` absent). Implemented as two dedicated test methods (public + confirm flow) per 3.2.
  - [x] 3.1.10 `test_owner_accept_with_preexisting_family_membership_promotes_and_keeps_both_rows` — invitee already has an active FAMILY_RESIDENT membership on the unit; accepts an OWNER invite → OWNER row created via `changeOwner` (bypasses `alreadyActive` skip), prior FAMILY_RESIDENT row remains active per `changeOwner`'s documented takeover semantics.
- [x] 3.2 [Directive 1] For the stale-accept test(s) in 3.1.9, cover BOTH flows with distinct expected HTTP status instead of hardcoding 409 for both:
  - Public `POST /api/accept-invitation` (`AuthController::acceptInvitation`, ~line 692–719): `\InvalidArgumentException` with code 409 maps straight to HTTP 409 (line ~707–708) → assert `assertStatus(409)`.
  - Authenticated `POST /api/accept-invitation-confirm` (`AuthController::acceptInvitationConfirm`, ~line 726–750): non-403 codes (including our 409) map to HTTP 400 (line ~738) → assert `assertStatus(400)`. Both assertions must additionally check: Spanish message present, `accepted_at` still null.
  - [x] 3.1.11 [sdd-verify follow-up] `test_revoked_owner_invitation_frees_the_unit_for_a_new_invite` — pending OWNER invite revoked via `DELETE /api/resident-invitation/{id}`, then a new OWNER invite for the same unit (different email) → 201. Closes the "Revoked/expired invite frees the unit" spec scenario for the revoked branch.
  - [x] 3.1.12 [sdd-verify follow-up] `test_expired_owner_invitation_frees_the_unit_for_a_new_invite` — pending OWNER invite whose `expires_at` is in the past (direct-insert pattern, mirrors `test_expired_invitation_cannot_be_accepted`), then a new OWNER invite for the same unit (different email) → 201. Closes the expired branch of the same spec scenario.
  - [x] 3.1.13 [sdd-verify follow-up] `test_owner_relationship_name_used_in_mail_context_is_propietario` — direct reflection call to `ResidentInvitationService::relationshipName(UnitRelationship::OWNER)` asserts `'propietario'`. Mail::fake() cannot observe `mail_context` (ephemeral, not persisted on `UserInvitation`), so this is the cheapest honest assertion for the "Mail context for an OWNER invite" spec scenario, per project memory on `Mail::fake` being unusable for this email path.

## Phase 4: Frontend — Schema

- [x] 4.1 In `frontend/src/modules/residentInvitation/schema.ts`: rewrite the `INVITABLE_RELATIONSHIPS` doc comment (lines ~29–34) to reflect the new gating (OWNER conditional, BILLING_CONTACT now included ungated); add `UNIT_RELATIONSHIP.BILLING_CONTACT` to the base `Set` (line ~35–39).
- [x] 4.2 Add exported `invitableRelationshipIds(opts: { canManageInvitations: boolean; hasActiveOwner?: boolean }): ReadonlySet<string>` — returns the base `INVITABLE_RELATIONSHIPS` set, plus `UNIT_RELATIONSHIP.OWNER` iff `opts.canManageInvitations && opts.hasActiveOwner === false`. Pure function, no React/store imports (matches design D6 testability requirement).

## Phase 5: Frontend — Modal & page wiring

- [x] 5.1 In `InviteResidentModal.tsx`: add optional prop `hasActiveOwner?: boolean` to `InviteResidentModalProps` (line ~29–34); import `usePermissions` and call `usePermissions('create.resident_invitation')`; replace the `relationshipOptions` `useMemo` (line ~63–69) to filter via `invitableRelationshipIds({ canManageInvitations, hasActiveOwner })` instead of the raw `INVITABLE_RELATIONSHIPS` set.
- [x] 5.2 In `UnitDetailPage.tsx`'s `useUnitDetailPage` hook: destructure `isSuccess: peopleLoaded` from the people `useQuery` (line ~357–368, currently only `data: people = []` is destructured); return `peopleLoaded` from the hook (add to the return object near line ~398).
- [x] 5.3 In `UnitDetailPage.tsx`'s page component: destructure `peopleLoaded` from `useUnitDetailPage()` (line ~426–450 block); pass `hasActiveOwner={peopleLoaded ? currentOwner != null : undefined}` to `<InviteResidentModal>` (line ~551–556).
- [x] 5.4 Verify `MyUnitPage.tsx`'s `<InviteResidentModal>` mount (~line 203) is left unchanged — no `hasActiveOwner` prop passed, so OWNER stays hidden in self-service per the tri-state contract. No code change; confirm during review only.

## Phase 6: Frontend — Tests

- [x] 6.1 Create `frontend/src/modules/residentInvitation/schema.test.ts` covering `invitableRelationshipIds`:
  - Base set always includes `BILLING_CONTACT`.
  - `canManageInvitations: false` → OWNER absent regardless of `hasActiveOwner`.
  - `canManageInvitations: true, hasActiveOwner: true` → OWNER absent.
  - `canManageInvitations: true, hasActiveOwner: undefined` → OWNER absent.
  - `canManageInvitations: true, hasActiveOwner: false` → OWNER present.

## Phase 7: Spec append (Directive 2)

- [x] 7.1 In `openspec/changes/invite-owner-vacant-unit/specs/resident-invitation/spec.md`, append a new scenario under "Requirement: One pending OWNER invitation per unit" (after the existing "Revoked/expired invite frees the unit" scenario, ~line 44): `#### Scenario: Pending invitation of a different relationship blocks OWNER invite` — GIVEN a unit with a pending non-OWNER invitation for email X, WHEN an admin invites email X as OWNER, THEN the request is rejected with a Mexican-Spanish message and the existing pending invitation keeps its original `unit_relationship_id` (not rotated).

## Phase 8: Verification

- [x] 8.1 Backend: `php artisan test --filter=ResidentInvitationOwnerTest` (host php, ONE process — never parallel with another `php artisan test` run). Result: 14 passed (58 assertions) — 11 original + 3 sdd-verify follow-up (revoked/expired-frees-unit, relationshipName reflection).
- [x] 8.2 Backend: `php artisan test tests/Feature/Housing/ResidentInvitationTest.php` (regression on the pre-existing suite — same file's tests must stay green since `INVITABLE_RELATIONSHIPS`/`relationshipName` changed). Result: 14 passed (58 assertions).
- [x] 8.3 Backend: `vendor/bin/pint --dirty` on `ResidentInvitationService.php` and the new test file. Result: passed, no changes needed.
- [x] 8.4 Frontend (from `frontend/`): `pnpm typecheck`. Result: clean, no errors.
- [x] 8.5 Frontend (from `frontend/`): `pnpm lint` (scoped to touched files). Result: 0 errors, 1 pre-existing `max-lines` warning on `UnitDetailPage.tsx` (was already over 500 lines before this change; net +7 lines).
- [x] 8.6 Frontend (from `frontend/`): `pnpm test`. Result: full suite 708 passed / 708 (92 test files), including the new `schema.test.ts` (5 cases).
