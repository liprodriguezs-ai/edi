import { Button } from '@/components/ui/button'
import { FollowUpCancelModal } from '@/modules/followUp/FollowUpCancelModal'
import { ReferralFormModal } from '@/modules/followUp/ReferralFormModal'
import { followUpChildName } from '@/modules/followUp/schema'
import { FollowUpResource } from '@/modules/followUp/services/FollowUpResource'
import { useFollowUpDetail } from '@/modules/followUp/hooks/useFollowUpDetail'
import { followUpErrorMessage } from '@/modules/followUp/utils/followUpErrors'
import { followUpStatusTone, followUpTypeLabel } from '@/modules/followUp/utils/statusCopy'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Skeleton } from 'boneyard-js/react'
import { useState } from 'react'

interface FollowUpDetailProps {
  followUpId: string
}

export function FollowUpDetail({ followUpId }: FollowUpDetailProps) {
  const { followUp, isLoading, isError, notFound, invalidate } = useFollowUpDetail(followUpId)

  const [cancelOpen, setCancelOpen] = useState(false)
  const [referralOpen, setReferralOpen] = useState(false)
  const [startConfirmOpen, setStartConfirmOpen] = useState(false)
  const [completeConfirmOpen, setCompleteConfirmOpen] = useState(false)
  const [isTransitioning, setIsTransitioning] = useState(false)

  if (isError && !isLoading) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Seguimiento no encontrado' : undefined}
        backTo="/follow-up"
        backLabel="Volver a seguimientos"
      />
    )
  }

  if (isLoading || !followUp) {
    return (
      <Skeleton name="follow-up-detail" loading>
        <div className="space-y-6">
          <div className="h-8 w-64 rounded bg-muted" />
          <div className="h-40 rounded bg-muted" />
        </div>
      </Skeleton>
    )
  }

  const statusKey = followUp.follow_up_status?.key
  const isOpen = statusKey === 'pending' || statusKey === 'in_progress' || statusKey === 'overdue'
  const canStart = isOpen && statusKey !== 'in_progress'
  const isReferralType = followUp.follow_up_type?.key === 'specialist_referral'

  const handleStart = async () => {
    setIsTransitioning(true)
    try {
      await FollowUpResource.start(followUp.id)
      toast.success('Seguimiento iniciado')
      setStartConfirmOpen(false)
      invalidate()
    } catch (err) {
      toast.error(followUpErrorMessage(err, 'No se pudo actualizar el seguimiento'))
    } finally {
      setIsTransitioning(false)
    }
  }

  const handleComplete = async () => {
    setIsTransitioning(true)
    try {
      await FollowUpResource.complete(followUp.id)
      toast.success('Seguimiento completado')
      setCompleteConfirmOpen(false)
      invalidate()
    } catch (err) {
      toast.error(followUpErrorMessage(err, 'No se pudo actualizar el seguimiento'))
    } finally {
      setIsTransitioning(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        back={{ to: '/follow-up', label: 'Volver a seguimientos' }}
        title={followUpChildName(followUp.child)}
        description={followUpTypeLabel(followUp.follow_up_type?.key)}
        meta={
          <>
            <StatusPill tone={followUpStatusTone(statusKey)}>{followUp.follow_up_status?.name ?? '—'}</StatusPill>
            <span className="text-xs">
              Fecha límite:{' '}
              <span className="font-mono tabular-nums">
                {displayFormatDate(followUp.due_date, 'mediumDate')}
              </span>
            </span>
          </>
        }
        actions={
          <PermissionGuard permission="update.follow_up">
            {canStart && (
              <Button variant="outline" onClick={() => setStartConfirmOpen(true)}>
                Iniciar
              </Button>
            )}
            {isOpen && <Button onClick={() => setCompleteConfirmOpen(true)}>Completar</Button>}
            {isOpen && (
              <Button variant="outline" onClick={() => setCancelOpen(true)}>
                Cancelar
              </Button>
            )}
          </PermissionGuard>
        }
      />

      {(followUp.cancellation_reason || followUp.notes || isReferralType) && (
        <div className="divide-y divide-border border border-border">
          {followUp.cancellation_reason && (
            <section className="space-y-1 p-4 sm:p-6">
              <h2 className="text-base font-semibold">Motivo de cancelación</h2>
              <p className="text-sm text-muted-foreground">{followUp.cancellation_reason}</p>
            </section>
          )}

          {followUp.notes && (
            <section className="space-y-1 p-4 sm:p-6">
              <h2 className="text-base font-semibold">Notas</h2>
              <p className="text-sm text-muted-foreground">{followUp.notes}</p>
            </section>
          )}

          {isReferralType && (
            <section className="p-4 sm:p-6">
              <div className="flex flex-row items-center justify-between">
                <h2 className="text-base font-semibold">Referencias</h2>
                <PermissionGuard permission="create.referral">
                  <Button size="sm" variant="outline" onClick={() => setReferralOpen(true)}>
                    Registrar referencia
                  </Button>
                </PermissionGuard>
              </div>

              {followUp.referrals && followUp.referrals.length > 0 ? (
                <div className="mt-4 divide-y divide-border border border-border">
                  {followUp.referrals.map((referral) => (
                    <div key={referral.id} className="p-3 text-sm">
                      <div className="font-medium">{referral.referral_specialty?.name ?? '—'}</div>
                      <div className="text-muted-foreground">
                        <span className="font-mono tabular-nums">
                          {displayFormatDate(referral.referred_at, 'mediumDate')}
                        </span>
                        {referral.institution && ` · ${referral.institution}`}
                      </div>
                      {referral.outcome_notes && (
                        <div className="mt-1 text-muted-foreground">{referral.outcome_notes}</div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <InlineEmpty className="mt-4">Sin referencias registradas.</InlineEmpty>
              )}
            </section>
          )}
        </div>
      )}

      {cancelOpen && (
        <FollowUpCancelModal
          followUpId={followUp.id}
          onClose={() => setCancelOpen(false)}
          onDone={() => {
            setCancelOpen(false)
            invalidate()
          }}
        />
      )}

      {referralOpen && (
        <ReferralFormModal
          followUpId={followUp.id}
          onClose={() => setReferralOpen(false)}
          onCreated={() => {
            setReferralOpen(false)
            invalidate()
          }}
        />
      )}

      <ConfirmDialog
        isOpen={startConfirmOpen}
        title="Iniciar seguimiento"
        description="El seguimiento pasará a estado “En proceso”. ¿Deseas continuar?"
        confirmLabel="Iniciar"
        isLoading={isTransitioning}
        onConfirm={() => void handleStart()}
        onCancel={() => setStartConfirmOpen(false)}
      />

      <ConfirmDialog
        isOpen={completeConfirmOpen}
        title="Completar seguimiento"
        description="El seguimiento quedará cerrado (solo lectura). ¿Deseas continuar?"
        confirmLabel="Completar"
        isLoading={isTransitioning}
        onConfirm={() => void handleComplete()}
        onCancel={() => setCompleteConfirmOpen(false)}
      />
    </div>
  )
}
