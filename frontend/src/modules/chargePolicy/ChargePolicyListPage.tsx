import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { IChargeConcept } from '@/modules/chargeConcept/schema'
import { periodicityCadenceLabel } from '@/modules/chargeConcept/helpers/chargeGeneration'
import { ChargeConceptResource } from '@/modules/chargeConcept/services/ChargeConceptResource'
import { PERIODICITY, type IChargePolicy } from '@/modules/chargePolicy/schema'
import { ChargePolicyResource } from '@/modules/chargePolicy/services/ChargePolicyResource'
import { useChargePolicyStore } from '@/modules/chargePolicy/store/chargePolicyStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const PERMISSIONS = {
  canCreate: 'create.charge_policy',
  canEdit: 'update.charge_policy',
  canDelete: 'delete.charge_policy',
} as const

function policyAmountDisplay(policy: IChargePolicy): string {
  if (moneyToMinorUnits(policy.fixed_amount ?? null) > 0) {
    return displayMoney(policy.fixed_amount)
  }
  if (moneyToMinorUnits(policy.ideal_amount ?? null) > 0) {
    return `${displayMoney(policy.ideal_amount)} (rango)`
  }
  if (moneyToMinorUnits(policy.prorate_total_amount ?? null) > 0) {
    return `${displayMoney(policy.prorate_total_amount)} (prorrateo)`
  }
  return '—'
}

/** El modo se DERIVA de la periodicidad: EVENTUAL es cobro único (directo); el resto, periódico. */
function modeLabel(policy: IChargePolicy): string {
  return policy.periodicity_id === PERIODICITY.EVENTUAL ? 'Directo' : 'Periódico'
}

/**
 * Políticas de cobro de UN concepto (relación 1:N). Se llega desde la lista de
 * conceptos. Crear/editar navega al form de página completa.
 */
export const ChargePolicyListPage = () => {
  const navigate = useNavigate()
  const { conceptId = '' } = useParams<{ conceptId: string }>()

  const {
    entity: concept,
    isError: conceptError,
    notFound: conceptNotFound,
    refetch: refetchConcept,
  } = useEntityDetail<IChargeConcept>({
    queryKey: ['charge-concept', conceptId],
    queryFn: () => ChargeConceptResource.show(conceptId),
    enabled: Boolean(conceptId),
  })

  const { onPageChanged, getList } = useBaseList<IChargePolicy>({
    store: useChargePolicyStore,
    resource: ChargePolicyResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'is_active', direction: 'desc' }],
      filters: [{ field: 'charge_concept_id', operator: '=', value: conceptId }],
      includes: [{ relation: 'amount_mode' }, { relation: 'periodicity' }],
    },
  })
  const { entities, pagination, isLoading } = useChargePolicyStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const permissions = useMultiplePermissions(PERMISSIONS)

  const crud = useBaseCrud<IChargePolicy, Record<string, unknown>>({
    store: useChargePolicyStore,
    resource: { delete: ChargePolicyResource.remove },
  })
  const isDeleting = useChargePolicyStore((s) => s.isDeleting)

  const [toDelete, setToDelete] = useState<IChargePolicy | null>(null)

  const handleDelete = useCallback(async () => {
    if (!toDelete) return
    try {
      await crud.onDelete(toDelete.id)
    } catch {
      // useBaseCrud.onDelete ya mostró el mensaje del backend; sólo evitamos el rechazo.
    } finally {
      // Reintentar daría el mismo error, así que el diálogo se cierra en ambos caminos.
      setToDelete(null)
    }
  }, [toDelete, crud])

  const headerTitle = useMemo(
    () => (concept ? `Programación de ${concept.name}` : 'Programación de cobros'),
    [concept]
  )

  // Si el concepto padre no existe (o falló su carga), no tiene sentido mostrar la lista
  // de programaciones huérfanas ni el botón de crear.
  if (conceptError) {
    return (
      <EntityNotFound
        variant={conceptNotFound ? 'notFound' : 'error'}
        title={conceptNotFound ? 'Concepto no encontrado' : undefined}
        backTo='/charge-concept'
        backLabel='Volver a conceptos'
        onRetry={conceptNotFound ? undefined : refetchConcept}
      />
    )
  }

  return (
    <div className='space-y-6'>
      <div>
        <Link
          to='/charge-concept'
          className='mb-1 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground'
        >
          <ArrowLeft className='size-3.5' />
          Conceptos de cobro
        </Link>
        <PageHeader
          title={headerTitle}
          description='Cada programación define monto, periodicidad y vigencia o alcance del concepto'
          actions={
            <PermissionGuard permission='create.charge_policy'>
              <Button
                className='rounded-full'
                onClick={() => navigate(`/charge-concept/${conceptId}/policy/new`)}
              >
                <Plus className='size-4' />
                Nueva programación
              </Button>
            </PermissionGuard>
          }
        />
      </div>

      <section className='space-y-6'>
        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Aún no hay programaciones'
            description='Crea la primera para definir cómo se cobra este concepto.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IChargePolicy>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Nombre</TableHead>
                    <TableHead scope='col'>Modo</TableHead>
                    <TableHead scope='col'>Periodicidad</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Monto
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {(permissions.canEdit || permissions.canDelete) && (
                      <TableHead scope='col' className='text-right'></TableHead>
                    )}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(policy) => (
                <TableRow key={policy.id}>
                  <TableCell className='font-medium'>{policy.name}</TableCell>
                  <TableCell>{modeLabel(policy)}</TableCell>
                  <TableCell>{periodicityCadenceLabel(policy.periodicity_id)}</TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {policyAmountDisplay(policy)}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={policy.is_active ? 'g' : 'r'}>
                      {policy.is_active ? 'Activa' : 'Inactiva'}
                    </StatusPill>
                  </TableCell>
                  {(permissions.canEdit || permissions.canDelete) && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <div className='flex items-center justify-end gap-1'>
                        {permissions.canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => navigate(`/charge-policy/${policy.id}`)}
                            aria-label={`Editar programación ${policy.name}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {permissions.canDelete && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => setToDelete(policy)}
                            aria-label={`Eliminar programación ${policy.name}`}
                          >
                            <Trash2 className='size-4' />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(policy) => policy.name}
              renderCardFields={(policy) => [
                { label: 'Modo', value: modeLabel(policy) },
                { label: 'Periodicidad', value: periodicityCadenceLabel(policy.periodicity_id) },
                {
                  label: 'Monto',
                  value: <span className='tabular-nums'>{policyAmountDisplay(policy)}</span>,
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={policy.is_active ? 'g' : 'r'}>
                      {policy.is_active ? 'Activa' : 'Inactiva'}
                    </StatusPill>
                  ),
                },
              ]}
              renderActions={
                permissions.canEdit || permissions.canDelete
                  ? (policy) => (
                      <div className='flex items-center gap-1'>
                        {permissions.canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => navigate(`/charge-policy/${policy.id}`)}
                            aria-label={`Editar programación ${policy.name}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {permissions.canDelete && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => setToDelete(policy)}
                            aria-label={`Eliminar programación ${policy.name}`}
                          >
                            <Trash2 className='size-4' />
                          </Button>
                        )}
                      </div>
                    )
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ConfirmDialog
        isOpen={Boolean(toDelete)}
        variant='delete'
        entityName={toDelete?.name}
        isLoading={isDeleting}
        onConfirm={async () => {
          await handleDelete()
          void getList()
        }}
        onCancel={() => setToDelete(null)}
      />
    </div>
  )
}
