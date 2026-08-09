import { UNIT_RELATIONSHIP } from '@/modules/unit/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export type ResidentInvitationStatus = 'pending' | 'expired' | 'accepted' | 'revoked'

export interface IResidentInvitation extends IEntity {
  email: string
  unit_id: string
  unit_relationship_id: string | null
  status: ResidentInvitationStatus
  expires_at?: string | null
}

/** Same-tenant unit where the invited email is already an active resident. */
export interface ISameTenantUnit {
  unit_id: string
  unit_label: string
}

export interface IEmailCheckResult {
  email_registered: boolean
  already_in_this_unit: boolean
  has_pending_invitation: boolean
  same_tenant_other_unit: boolean
  same_tenant_units: ISameTenantUnit[]
}

/**
 * Resident types always offered in the invite UI: Familiar, Inquilino,
 * Co-propietario, Contacto de cobranza. OWNER is conditionally added on top of
 * this base set — see `invitableRelationshipIds()` below — because it is
 * admin-gated and requires the target unit to be vacant.
 */
export const INVITABLE_RELATIONSHIPS: ReadonlySet<string> = new Set([
  UNIT_RELATIONSHIP.FAMILY_RESIDENT,
  UNIT_RELATIONSHIP.RENTER,
  UNIT_RELATIONSHIP.CO_OWNER,
  UNIT_RELATIONSHIP.BILLING_CONTACT,
])

/**
 * Relationship ids to offer in the invite modal's selector. Pure function (no
 * React/store imports) so it is directly unit-testable: the base set always
 * applies, plus OWNER iff the inviter can manage invitations AND the target
 * unit is confirmed vacant (`hasActiveOwner === false`). Any other value of
 * `hasActiveOwner` (true or undefined/unknown) keeps OWNER hidden — the safe
 * default while the unit's owner status hasn't loaded yet.
 */
export function invitableRelationshipIds(opts: {
  canManageInvitations: boolean
  hasActiveOwner?: boolean
}): ReadonlySet<string> {
  if (opts.canManageInvitations && opts.hasActiveOwner === false) {
    return new Set([...INVITABLE_RELATIONSHIPS, UNIT_RELATIONSHIP.OWNER])
  }

  return INVITABLE_RELATIONSHIPS
}

export const inviteFormSchema = z.object({
  email: z.string().min(1, 'El correo es requerido').email('Ingresa un correo válido'),
  unit_relationship_id: z.string().min(1, 'Elige el tipo de residente'),
})

export type InviteFormValues = z.infer<typeof inviteFormSchema>

export const INVITATION_STATUS_META: Record<
  ResidentInvitationStatus,
  { label: string; color: string }
> = {
  pending: { label: 'Pendiente', color: '#B45309' },
  expired: { label: 'Vencida', color: '#6B7280' },
  accepted: { label: 'Aceptada', color: '#15803D' },
  revoked: { label: 'Cancelada', color: '#B91C1C' },
}
