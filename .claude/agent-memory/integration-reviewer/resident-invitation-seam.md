---
name: resident-invitation-seam
description: Resident Invitation feature seam (back↔front). Custom non-Orion endpoints, presentInvitation shape, verify top-level envelope, account_exists branch, auth-timed confirm. Verified ALIGNED 2026-06-27.
metadata:
  type: project
---

# Resident Invitation seam (VERIFIED ALIGNED 2026-06-27)

Branches `feat/resident-invitation` on both repos. All custom (non-Orion) endpoints.

## Endpoint envelope map (source of truth = controllers, all untracked ?? files)
- Backend serializer: `Modules/Housing/app/Http/Controllers/ResidentInvitationController::presentInvitation()` returns `{id,email,unit_id,unit_relationship_id,status,expires_at,created_at}`. `unit_id` = `inviteable_id`. ISO8601 dates. token/token_hash NEVER serialized.
- `status` = match(accepted_at→'accepted', revoked_at→'revoked', isExpired→'expired', else 'pending'). FE `ResidentInvitationStatus` union matches the 4 exactly.
- check-email / index / resend / store all wrap in `{data:...}` (+ store adds `{message,warnings}`); FE reads `data.data` / `data.warnings`. revoke/deactivate/block return `{message}` only (FE ignores body).
- **verify-invitation-token is the ONE TOP-LEVEL payload** (no `{data}`): `{valid,email,account_exists,expires_at,(cluster_name,unit_label,relationship_name,inviter_name)}`. FE `verifyInvitationTokenRequest` returns `response.data` (whole body). Correct.
- `account_exists` present in EVERY `valid:true` response (AuthController::verifyInvitationToken builds one `$payload`); the `valid:false` early-return omits it. FE `accept-invitation-form` branches `tokenState.account_exists ? ExistingAccountForm : NewAccountForm`. Safe.

## Auth-timed confirm (the tricky one)
`/auth/accept-invitation/confirm` requires `auth:sanctum`. FE `onSubmitExisting` does `await login(...)` (which calls `sessionStore.getState().setSession(result)` SYNCHRONOUSLY) BEFORE `await acceptInvitationConfirmRequest`. apiClient request interceptor reads `sessionStore.getState().token` per-request → Bearer attached. ALIGNED.

## Status codes
- 422 ValidationException (dup / invalid relationship / renter-without-owner) bubbles from store; FE InviteResidentModal `onError` → `formatApiErrorMessage(ApiError)`. OK.
- 403 (email-mismatch on confirm / non-owner authorizeUnit), 404 (IDOR / cross-tenant block via findOrFail + abort_unless), 409 (existing account in password flow, code 409 mapped in AuthController::acceptInvitation) — all caught generically by FE toasts; nothing breaks.

## Relationship UUIDs (central catalog, fixed constants)
FE `UNIT_RELATIONSHIP` (modules/unit/schema.ts) UUIDs == BE `UnitRelationship` constants EXACTLY (OWNER, CO_OWNER, RENTER, FAMILY_RESIDENT, BILLING_CONTACT).

## Authz parity (UI gate vs BE authz)
- Owners invite from MyUnitPage gated by `canManage` = `[OWNER,CO_OWNER].includes(rel) || is_billing_owner` — EXACT mirror of BE `ResidentInvitationService::isActiveOwner`.
- Admins invite from UnitDetailPage gated by `PermissionGuard create.resident_invitation`. BE authorizeUnit = isActiveOwner OR tokenCan('create.resident_invitation') OR '*'. Both paths covered.
- Permission keys seeded (permissions.csv): read/create/delete.resident_invitation + block.resident. block.resident & update.unit_user gate the block/baja UI. All match BE tokenCan checks.

## Known DRIFT (non-breaking, flagged)
- **INVITABLE_RELATIONSHIPS subset mismatch**: BE `ResidentInvitationService::INVITABLE_RELATIONSHIPS` = 4 (FAMILY_RESIDENT, RENTER, CO_OWNER, **BILLING_CONTACT**). FE `schema.ts INVITABLE_RELATIONSHIPS` = 3 (drops BILLING_CONTACT). BE permissive so no break, but FE can't invite a billing contact the BE explicitly supports (BE sets is_billing_owner from it). FE comment only documents excluding OWNER, not BILLING_CONTACT — likely unintentional. Re-check on any change to either list.
- FE sends `password_confirmation` to `/auth/accept-invitation`; BE reads only token+password (ignores confirmation, no server-side password policy). Harmless; FE zod validates match.
- 409 in NewAccountForm only toasts; no auto-switch to ExistingAccountForm (minor UX).
