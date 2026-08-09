import { InviteResidentModal } from '@/modules/residentInvitation/InviteResidentModal'
import { PendingInvitationsList } from '@/modules/residentInvitation/PendingInvitationsList'
import { ResidentInvitationResource } from '@/modules/residentInvitation/services/ResidentInvitationResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { UNIT_RELATIONSHIP, type IUnit, type IUnitUser } from '@/modules/unit/schema'
import { useSession } from '@/shared/auth/useSession'
import { CatalogPill } from '@/shared/components/CatalogPill'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Car, FileText, History, UserMinus, UserPlus, UserRound } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

const OWNER_RELATIONSHIPS: string[] = [UNIT_RELATIONSHIP.OWNER, UNIT_RELATIONSHIP.CO_OWNER]

/**
 * Vista "Mi unidad" del residente (§6.4.1): sus unidades activas con residentes y
 * vehículos. Los propietarios pueden invitar nuevos residentes, ver invitaciones
 * pendientes y dar de baja a otros residentes. Datos de GET /unit/my.
 */
export const MyUnitPage = () => {
  const { data: units = [], isLoading } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })

  if (isLoading) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando tu unidad…</p>
  }

  if (units.length === 0) {
    return (
      <div className='space-y-6'>
        <PageHeader title='Mi unidad' description='Información de tu vivienda en el condominio' />
        <p className='rounded-[14px] border border-border bg-card px-6 py-10 text-center text-sm text-[var(--ink-3)]'>
          Aún no tienes una unidad asociada. Contacta a la administración de tu condominio.
        </p>
      </div>
    )
  }

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Mi unidad'
        description='Información de tu vivienda en el condominio'
        actions={
          <>
            <Link
              to='/cluster-documents'
              className='inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-[18px] py-[9px] text-[13px] font-medium hover:border-[var(--ink-3)]'
            >
              <FileText className='size-4' />
              Documentos del condominio
            </Link>
            <Link
              to='/my-access-log'
              className='inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-[18px] py-[9px] text-[13px] font-medium hover:border-[var(--ink-3)]'
            >
              <History className='size-4' />
              Historial de accesos
            </Link>
          </>
        }
      />

      {units.map((unit) => (
        <MyUnitCard key={unit.id} unit={unit} />
      ))}
    </div>
  )
}

function MyUnitCard({ unit }: { unit: IUnit }) {
  const { profile } = useSession()
  const queryClient = useQueryClient()
  const [isInviteOpen, setIsInviteOpen] = useState(false)
  const [toDeactivate, setToDeactivate] = useState<IUnitUser | null>(null)

  const currentUserId = profile?.user?.id
  const myAssoc = unit.unit_users?.find(
    (assoc) => assoc.user?.id === currentUserId && !assoc.end_date
  )
  const canManage =
    !!myAssoc &&
    (OWNER_RELATIONSHIPS.includes(myAssoc.unit_relationship_id) || !!myAssoc.is_billing_owner)

  const unitLabel = unit.full_identifier ?? unit.number ?? 'tu unidad'

  const deactivate = useMutation({
    mutationFn: (unitUserId: string) => ResidentInvitationResource.deactivateMembership(unitUserId),
    onSuccess: () => {
      toast.success(
        `${toDeactivate?.user?.name ?? 'El residente'} fue dado de baja de ${unitLabel}.`
      )
      setToDeactivate(null)
      void queryClient.invalidateQueries({ queryKey: ['unit', 'my'] })
    },
    onError: () => {
      toast.error('No se pudo dar de baja al residente')
      setToDeactivate(null)
    },
  })

  return (
    <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
      <div className='flex flex-wrap items-center justify-between gap-3 border-b border-[var(--line-2)] px-5 py-4'>
        <div>
          <h2 className='text-[19px] font-semibold tracking-[-0.01em]'>{unitLabel}</h2>
          <p className='text-[12.5px] text-[var(--ink-3)]'>
            {unit.unit_type?.name}
            {unit.section?.name ? ` · ${unit.section.name}` : ''}
          </p>
        </div>
        <div className='flex items-center gap-2'>
          {canManage && (
            <button
              type='button'
              onClick={() => setIsInviteOpen(true)}
              className='inline-flex items-center gap-1.5 rounded-full bg-primary px-[16px] py-[8px] text-[13px] font-medium text-primary-foreground hover:bg-primary/90'
            >
              <UserPlus className='size-4' />
              Invitar residente
            </button>
          )}
          <CatalogPill name={unit.unit_status?.name} color={unit.unit_status?.color} />
        </div>
      </div>

      <div className='grid grid-cols-1 gap-0 md:grid-cols-2'>
        <div className='border-b border-[var(--line-2)] md:border-b-0 md:border-r'>
          <h3 className='px-5 pt-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Residentes
          </h3>
          {(unit.unit_users?.length ?? 0) === 0 ? (
            <p className='px-5 py-4 text-[13px] text-[var(--ink-3)]'>Sin residentes registrados.</p>
          ) : (
            <div className='px-2 py-2'>
              {unit.unit_users!.map((assoc) => (
                <div key={assoc.id} className='flex items-center gap-2.5 rounded-[10px] px-3 py-2'>
                  <span className='grid size-8 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[var(--green)]'>
                    <UserRound className='size-4' />
                  </span>
                  <div className='min-w-0 flex-1'>
                    <p className='truncate text-[13px] font-medium'>{assoc.user?.name ?? '—'}</p>
                    <p className='text-[11.5px] text-[var(--ink-3)]'>
                      {assoc.unit_relationship?.name ?? ''}
                      {assoc.start_date
                        ? ` · desde ${displayFormatDate(assoc.start_date, 'mediumDate')}`
                        : ''}
                    </p>
                  </div>
                  {canManage && assoc.user?.id !== currentUserId && (
                    <button
                      type='button'
                      onClick={() => setToDeactivate(assoc)}
                      aria-label={`Dar de baja a ${assoc.user?.name ?? 'residente'}`}
                      className='grid size-7 flex-none place-items-center rounded-md border border-border text-[var(--ink-3)] hover:border-destructive hover:text-destructive'
                    >
                      <UserMinus className='size-4' aria-hidden />
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}

          {canManage && <PendingInvitationsList unitId={unit.id} canManage />}
        </div>

        <div>
          <h3 className='px-5 pt-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Vehículos
          </h3>
          {(unit.vehicles?.length ?? 0) === 0 ? (
            <p className='px-5 py-4 text-[13px] text-[var(--ink-3)]'>Sin vehículos registrados.</p>
          ) : (
            <div className='px-2 py-2'>
              {unit.vehicles!.map((vehicle) => (
                <div
                  key={vehicle.id}
                  className='flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-[13px]'
                >
                  <Car className='size-4 flex-none text-[var(--ink-3)]' aria-hidden />
                  <span className='font-medium'>
                    {[vehicle.make, vehicle.model, vehicle.year].filter(Boolean).join(' ') ||
                      'Vehículo'}
                  </span>
                  <span className='ml-auto rounded-md border border-border bg-[var(--surface-2)] px-2 py-0.5 font-mono text-[11.5px]'>
                    {vehicle.plate}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {canManage && (
        <InviteResidentModal
          unitId={unit.id}
          unitLabel={unitLabel}
          isOpen={isInviteOpen}
          onClose={() => setIsInviteOpen(false)}
        />
      )}

      <ConfirmDialog
        isOpen={toDeactivate !== null}
        variant='delete'
        title={`Dar de baja a ${toDeactivate?.user?.name ?? 'residente'}`}
        description={`Se quitará a ${toDeactivate?.user?.name ?? 'esta persona'} de ${unitLabel} a partir de hoy. Conserva su cuenta y el acceso a otras unidades donde sea residente. Podrás volver a invitarla más adelante.`}
        confirmLabel='Dar de baja'
        cancelLabel='Volver'
        isLoading={deactivate.isPending}
        onConfirm={() => toDeactivate && deactivate.mutate(toDeactivate.id)}
        onCancel={() => setToDeactivate(null)}
      />
    </div>
  )
}
