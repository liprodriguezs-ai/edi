import {
  INVITATION_STATUS_META,
  type IResidentInvitation,
} from '@/modules/residentInvitation/schema'
import { useUnitInvitations } from '@/modules/residentInvitation/hooks/useUnitInvitations'
import { CatalogPill } from '@/shared/components/CatalogPill'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { MailPlus, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'

interface PendingInvitationsListProps {
  unitId: string
  /** Whether the viewer can resend/cancel (owner or admin). */
  canManage: boolean
}

/**
 * Embedded list of pending resident invitations for a unit, with resend/cancel.
 * Renders nothing when there are no pending invitations.
 */
export function PendingInvitationsList({ unitId, canManage }: PendingInvitationsListProps) {
  const { data: invitations = [], isLoading, resend, revoke } = useUnitInvitations(unitId)
  const { catalogs, enable } = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.UNIT_RELATIONSHIP]: { _sort: 'order' } }), [])
  )
  const [toCancel, setToCancel] = useState<IResidentInvitation | null>(null)

  // El catálogo de parentescos solo se necesita para rotular invitaciones
  // pendientes; difiérelo hasta que efectivamente haya algo que mostrar.
  const hasPendingInvitations = !isLoading && invitations.length > 0
  useEffect(() => {
    if (hasPendingInvitations) {
      enable()
    }
  }, [hasPendingInvitations, enable])

  const relationshipName = (id: string | null) =>
    (catalogs[CatalogType.UNIT_RELATIONSHIP] ?? []).find((r) => r.id === id)?.name

  if (isLoading || invitations.length === 0) {
    return null
  }

  const onResend = (invitation: IResidentInvitation) => {
    resend.mutate(invitation.id, {
      onSuccess: () =>
        toast.success({
          title: `Invitación reenviada a ${invitation.email}`,
          description: 'Le enviamos un enlace nuevo con vencimiento renovado.',
        }),
      onError: () => toast.error('No se pudo reenviar la invitación'),
    })
  }

  const onConfirmCancel = () => {
    if (!toCancel) return
    const invitation = toCancel
    revoke.mutate(invitation.id, {
      onSuccess: () => {
        toast.success('Invitación cancelada.')
        setToCancel(null)
      },
      onError: () => {
        toast.error('No se pudo cancelar la invitación')
        setToCancel(null)
      },
    })
  }

  return (
    <section aria-label="Invitaciones pendientes" className="px-2 py-2">
      <h3 className="px-3 pb-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">
        Invitaciones pendientes
      </h3>
      <ul className="flex flex-col gap-1">
        {invitations.map((invitation) => {
          const meta = INVITATION_STATUS_META[invitation.status]
          const isExpired = invitation.status === 'expired'
          return (
            <li
              key={invitation.id}
              className="flex flex-wrap items-center gap-2.5 rounded-[10px] px-3 py-2"
            >
              <span className="grid size-8 flex-none place-items-center rounded-full bg-amber-500/10 text-amber-700">
                <MailPlus className="size-4" aria-hidden />
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-[13px] font-medium">{invitation.email}</p>
                <p className="text-[11.5px] text-[var(--ink-3)]">
                  {relationshipName(invitation.unit_relationship_id) ?? 'Residente'}
                  {invitation.expires_at
                    ? ` · ${isExpired ? 'Venció' : 'Vence'} el ${displayFormatDate(invitation.expires_at, 'mediumDate')}`
                    : ''}
                </p>
              </div>
              <CatalogPill name={meta.label} color={meta.color} />
              {canManage && (
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    onClick={() => onResend(invitation)}
                    disabled={resend.isPending}
                    className="rounded-md border border-border px-2.5 py-1 text-[12px] font-medium hover:border-[var(--ink-3)] disabled:opacity-50"
                  >
                    Reenviar
                  </button>
                  <button
                    type="button"
                    onClick={() => setToCancel(invitation)}
                    aria-label={`Cancelar invitación a ${invitation.email}`}
                    className="grid size-7 place-items-center rounded-md border border-border text-[var(--ink-3)] hover:border-destructive hover:text-destructive"
                  >
                    <X className="size-4" aria-hidden />
                  </button>
                </div>
              )}
            </li>
          )
        })}
      </ul>

      <ConfirmDialog
        isOpen={toCancel !== null}
        variant="delete"
        title="Cancelar invitación"
        description={
          toCancel
            ? `La invitación a ${toCancel.email} dejará de funcionar y la persona ya no podrá usar el enlace que recibió. Podrás volver a invitarla cuando quieras.`
            : ''
        }
        confirmLabel="Cancelar invitación"
        cancelLabel="Volver"
        isLoading={revoke.isPending}
        onConfirm={onConfirmCancel}
        onCancel={() => setToCancel(null)}
      />
    </section>
  )
}
