import { Button } from '@/components/ui/button'
import { periodicityCadenceLabel } from '@/modules/chargeConcept/helpers/chargeGeneration'
import { PERIODICITY, type IChargePolicy } from '@/modules/chargePolicy/schema'
import { ChargePolicyResource } from '@/modules/chargePolicy/services/ChargePolicyResource'
import { useChargePolicyStore } from '@/modules/chargePolicy/store/chargePolicyStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const PERMISSIONS = {
  canCreate: 'create.charge_policy',
  canEdit: 'update.charge_policy',
  canDelete: 'delete.charge_policy',
} as const

/** Resumen legible del monto según el modo configurado en la política. */
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

/**
 * Periodicidad: el modo se DERIVA de la periodicidad — EVENTUAL es un cobro único
 * (directo); cualquier otra periodicidad es recurrente (la cadencia).
 */
function periodicityLabel(policy: IChargePolicy): string {
  if (policy.periodicity_id === PERIODICITY.EVENTUAL) {
    return 'Directo'
  }
  return periodicityCadenceLabel(policy.periodicity_id)
}

/** Vigencia: rango de fechas en recurrente; el cobro eventual (directo) no tiene ventana. */
function validityLabel(policy: IChargePolicy): string {
  if (policy.periodicity_id === PERIODICITY.EVENTUAL) {
    return 'Cobro directo'
  }
  const from = displayFormatDate(policy.valid_from, 'mediumDate')
  const to = displayFormatDate(policy.valid_to, 'mediumDate')
  if (!from && !to) {
    return '—'
  }
  return `${from || '—'} – ${to || '—'}`
}

/** Concepto al que pertenece la política (code/clave + nombre, o el id como último recurso). */
function conceptLabel(policy: IChargePolicy): string {
  if (policy.concept) {
    return `${policy.concept.code} · ${policy.concept.name}`
  }
  return '—'
}

/**
 * Lista GLOBAL de todas las políticas de cobro del clúster (sin filtrar por
 * concepto). Es la pantalla que abre el menú "Programación de Cobros" (/charge-policy).
 * Cada fila navega al cockpit de la política. "Nueva programación" navega directo al
 * alta global (/charge-policy/new), donde el concepto es la primera sección del form.
 */
export const ChargePolicyAllListPage = () => {
  const navigate = useNavigate()

  const { onPageChanged, getList } = useBaseList<IChargePolicy>({
    store: useChargePolicyStore,
    resource: ChargePolicyResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'is_active', direction: 'desc' }],
      includes: [{ relation: 'concept' }, { relation: 'periodicity' }, { relation: 'amount_mode' }],
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

  const showActions = permissions.canEdit || permissions.canDelete

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

  const editAction = (policy: IChargePolicy) =>
    permissions.canEdit ? (
      <Button
        variant='ghost'
        size='icon'
        className='size-8'
        onClick={() => navigate(`/charge-policy/${policy.id}`)}
        aria-label={`Editar programación ${policy.name}`}
      >
        <Pencil className='size-4' />
      </Button>
    ) : null

  const deleteAction = (policy: IChargePolicy) =>
    permissions.canDelete ? (
      <Button
        variant='ghost'
        size='icon'
        className='size-8'
        onClick={() => setToDelete(policy)}
        aria-label={`Eliminar programación ${policy.name}`}
      >
        <Trash2 className='size-4' />
      </Button>
    ) : null

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Programación de cobros'
        description='Todas las programaciones de cobro del condominio: monto, periodicidad y vigencia por concepto'
        actions={
          <PermissionGuard permission='create.charge_policy'>
            <Button className='rounded-full' onClick={() => navigate('/charge-policy/new')}>
              <Plus className='size-4' />
              Nueva programación
            </Button>
          </PermissionGuard>
        }
      />

      <section className='space-y-6'>
        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Aún no hay programaciones'
            description='Crea una desde un concepto de cobro para definir cómo se cobra.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IChargePolicy>
              breakpoint='lg'
              rows={entities}
              onRowClick={(policy) => navigate(`/charge-policy/${policy.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Concepto</TableHead>
                    <TableHead scope='col'>Programación</TableHead>
                    <TableHead scope='col'>Periodicidad</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Monto
                    </TableHead>
                    <TableHead scope='col'>Vigencia</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {showActions && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(policy) => (
                <TableRow key={policy.id}>
                  <TableCell className='font-medium'>{conceptLabel(policy)}</TableCell>
                  <TableCell>{policy.name}</TableCell>
                  <TableCell>{periodicityLabel(policy)}</TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {policyAmountDisplay(policy)}
                  </TableCell>
                  <TableCell className='text-[12.5px] text-[var(--ink-3)]'>
                    {validityLabel(policy)}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={policy.is_active ? 'g' : 'r'}>
                      {policy.is_active ? 'Activa' : 'Inactiva'}
                    </StatusPill>
                  </TableCell>
                  {showActions && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <div className='flex items-center justify-end gap-1'>
                        {editAction(policy)}
                        {deleteAction(policy)}
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(policy) => (
                <span>
                  <span className='font-medium'>{policy.name}</span>
                  <span className='block text-[12px] text-[var(--ink-3)]'>
                    {conceptLabel(policy)}
                  </span>
                </span>
              )}
              renderCardFields={(policy) => [
                { label: 'Periodicidad', value: periodicityLabel(policy) },
                {
                  label: 'Monto',
                  value: <span className='tabular-nums'>{policyAmountDisplay(policy)}</span>,
                },
                { label: 'Vigencia', value: validityLabel(policy) },
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
                showActions
                  ? (policy) => (
                      <div className='flex items-center gap-1'>
                        {editAction(policy)}
                        {deleteAction(policy)}
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
