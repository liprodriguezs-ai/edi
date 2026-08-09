import { invitableRelationshipIds } from '@/modules/residentInvitation/schema'
import { UNIT_RELATIONSHIP } from '@/modules/unit/schema'
import { describe, expect, it } from 'vitest'

describe('invitableRelationshipIds', () => {
  it('always includes BILLING_CONTACT in the base set', () => {
    const ids = invitableRelationshipIds({ canManageInvitations: false })
    expect(ids.has(UNIT_RELATIONSHIP.BILLING_CONTACT)).toBe(true)
  })

  it('excludes OWNER when the inviter cannot manage invitations, regardless of vacancy', () => {
    const idsVacant = invitableRelationshipIds({
      canManageInvitations: false,
      hasActiveOwner: false,
    })
    const idsUnknown = invitableRelationshipIds({ canManageInvitations: false })

    expect(idsVacant.has(UNIT_RELATIONSHIP.OWNER)).toBe(false)
    expect(idsUnknown.has(UNIT_RELATIONSHIP.OWNER)).toBe(false)
  })

  it('excludes OWNER when the unit already has an active owner', () => {
    const ids = invitableRelationshipIds({ canManageInvitations: true, hasActiveOwner: true })
    expect(ids.has(UNIT_RELATIONSHIP.OWNER)).toBe(false)
  })

  it('excludes OWNER when vacancy is unknown (undefined)', () => {
    const ids = invitableRelationshipIds({ canManageInvitations: true, hasActiveOwner: undefined })
    expect(ids.has(UNIT_RELATIONSHIP.OWNER)).toBe(false)
  })

  it('includes OWNER when the inviter can manage invitations and the unit is confirmed vacant', () => {
    const ids = invitableRelationshipIds({ canManageInvitations: true, hasActiveOwner: false })
    expect(ids.has(UNIT_RELATIONSHIP.OWNER)).toBe(true)
  })
})
