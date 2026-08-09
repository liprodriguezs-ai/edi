---
name: resident-invitation-feature
description: Resident-invitation module (residentInvitation/) — all-custom non-Orion endpoints, TanStack keyed-by-unit, gating gap in UnitDetailPage, recurring catalog-cast smell
metadata:
  type: pattern
---

# Resident Invitation feature (feat/resident-invitation)

Module `src/modules/residentInvitation/`: owner/admin invites residents by email; invitee
activates (new account) or links existing account via login; pending list with resend/revoke;
membership deactivate; admin block (disable account + blacklist).

## Conventions observed (correct, reuse as precedent)
- `ResidentInvitationResource` is a PLAIN object using `apiClient` directly (NOT createCrudApi),
  because EVERY endpoint is custom/non-Orion (check-email, invite, /unit/{id}/invitations,
  resend, deactivate, block). This is an accepted deviation when there is no Orion CRUD base.
  apiClient stays in the service; components import only `ApiError`/`formatApiErrorMessage`.
- Per-unit TanStack keys, no shared createEntityStore: `['unit','invitations',unitId]`,
  email check `['invitation-email-check', debounced, unitId]`, list `['unit','my']`.
  Invalidations match these keys exactly. This is the canonical pattern for "N units on one
  page must not collide" — mirror it for any embedded per-entity list.
- BlockResidentModal uses NATIVE form submit (`<Button type="submit" form="block-resident-form">`)
  — the GOOD pattern vs the type=button+onClick smell seen in Access CorrectionModal.
- accept-invitation route moved OUT of PublicOnlyRoute to a neutral route so existing-account
  invitees who are already authenticated aren't redirected.

## Recurring gap to check on every review of this module
- GATING INCONSISTENCY: `UnitDetailPage.tsx:249` renders `<PendingInvitationsList unitId canManage />`
  with canManage hardcoded true → resend/revoke buttons show to anyone with read.unit, while the
  invite button (PeopleCard:218) is gated `create.resident_invitation` and Block is gated
  `block.resident` (usePermissions, lines 307-308). Pass a real permission to PendingInvitationsList.
- Frontend depends on custom abilities `create.resident_invitation` and `block.resident` — verify
  they exist in backend permissions.csv + RolePermissionTableSeeder or buttons never render / 403.
- Recurring unnecessary catalog cast (see catalog-cast-pattern.md): `(rel as { name?: string }).name`
  (InviteResidentModal:180) and `...?.name as string | undefined` (PendingInvitationsList:32-34).
  IEntity already has `name?`.
- InviteResidentModal mounted unconditionally in MyUnitCard (gated only by canManage, not isOpen) →
  useForm/useCatalogs/useEmailCheck run per managed unit while closed. Mount-on-open preferred.
- `InviteResult.warnings` returned by the resource but discarded in onSuccess (only `{invitation}`
  destructured) — dead backend data.
