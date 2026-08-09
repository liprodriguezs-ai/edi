# Delta for Resident Invitation — Invite Owner for Vacant Unit

> First formal spec for this domain (`openspec/specs/` does not exist yet). All
> requirements below are ADDED; pre-existing resident-invitation behavior for
> FAMILY_RESIDENT/RENTER/CO_OWNER/BILLING_CONTACT is unchanged and out of scope.

## ADDED Requirements

### Requirement: OWNER invite eligibility gated to admins and vacant units

The system MUST allow `unit_relationship_id = OWNER` in `inviteToUnit` only
when the inviter holds `create.resident_invitation` (or `*`) permission —
never via the self-service `isActiveOwner` path — AND the unit has zero
active OWNER memberships (`unitHasActiveOwner()`).

#### Scenario: Admin invites owner to vacant unit
- GIVEN a unit with no active OWNER membership
- WHEN an admin with `create.resident_invitation` invites with `unit_relationship_id=OWNER`
- THEN the invitation is created and sent

#### Scenario: Resident self-service attempts OWNER invite
- GIVEN an inviter authorized only via the active-owner self-service path
- WHEN they attempt an OWNER invite
- THEN it is rejected, even if the unit is vacant

#### Scenario: Unit already has an active owner
- GIVEN a unit with an active OWNER membership
- WHEN an admin attempts an OWNER invite
- THEN `inviteToUnit` throws a ValidationException with a Mexican-Spanish message; no invitation is created

### Requirement: One pending OWNER invitation per unit

The system MUST reject a new OWNER invite for a unit that already has an
existing pending (unaccepted, unrevoked, unexpired) OWNER invitation.

#### Scenario: Second OWNER invite blocked
- GIVEN a unit with a pending OWNER invitation
- WHEN an admin sends a second OWNER invite for the same unit
- THEN the request is rejected and the existing pending invitation is untouched

#### Scenario: Revoked/expired invite frees the unit
- GIVEN a unit whose only OWNER invitation was revoked or has expired
- WHEN an admin sends a new OWNER invite
- THEN the invitation is created

#### Scenario: Pending invitation of a different relationship blocks OWNER invite
- GIVEN a unit with a pending non-OWNER invitation for email X
- WHEN an admin invites email X as OWNER
- THEN the request is rejected with a Mexican-Spanish message and the existing pending invitation keeps its original `unit_relationship_id` (not rotated)

### Requirement: OWNER acceptance routes through changeOwner with re-check

`acceptToUnit`'s OWNER branch MUST re-check "unit has zero active owners"
inside the same DB transaction used for account/membership creation, and on
success MUST call `UnitUserService::changeOwner` (not a plain membership
create) so single-owner and billing-takeover invariants hold and
`changeOwner`'s notification fires exactly once.

#### Scenario: Clean accept
- GIVEN a pending OWNER invitation for a unit still vacant at accept time
- WHEN the invitee accepts
- THEN `changeOwner` creates the OWNER membership with `is_billing_owner=true`, and the owner-change notification fires once (no duplicate from `acceptToUnit`)

#### Scenario: Stale accept — owner appeared meanwhile
- GIVEN a pending OWNER invitation whose unit gained an active owner after the invite was sent
- WHEN the invitee accepts
- THEN the request is rejected with a 4xx Mexican-Spanish error, the invitation token is NOT consumed (`accepted_at` stays null), and the invitation remains pending for admin follow-up

### Requirement: relationshipName includes OWNER

`relationshipName()` MUST map `UnitRelationship::OWNER` to `'propietario'`
instead of falling back to the generic `'residente'` default.

#### Scenario: Mail context for an OWNER invite
- GIVEN an OWNER invite is created
- WHEN the mail context is built
- THEN `relationship_name = 'propietario'`

### Requirement: Frontend OWNER option gated behind permission and vacancy

`InviteResidentModal` MUST accept an optional `hasActiveOwner` prop and offer
OWNER in the relationship selector iff the current user holds
`create.resident_invitation` permission AND `hasActiveOwner === false`. When
the prop is omitted, OWNER MUST stay hidden (covers self-service mounts,
e.g. `MyUnitCard`).

#### Scenario: Admin on vacant unit sees OWNER
- GIVEN `UnitDetailPage` renders the modal with `hasActiveOwner=false` for an admin
- WHEN the modal opens
- THEN OWNER appears in the relationship options

#### Scenario: Self-service modal never offers OWNER
- GIVEN `MyUnitCard` renders the modal without the `hasActiveOwner` prop
- WHEN the modal opens
- THEN OWNER is absent regardless of permission

#### Scenario: Unit already has an owner
- GIVEN `UnitDetailPage` renders the modal with `hasActiveOwner=true`
- WHEN the modal opens
- THEN OWNER is absent

### Requirement: BILLING_CONTACT available in the frontend invite UI

The frontend relationship set MUST include BILLING_CONTACT ungated, matching
backend acceptance (already in `INVITABLE_RELATIONSHIPS` server-side).

#### Scenario: Any inviter selects billing contact
- GIVEN any user with invite access opens the modal
- WHEN they open the relationship selector
- THEN BILLING_CONTACT is an available option
