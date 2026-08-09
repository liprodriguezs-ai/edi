import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import type { IUnitUser } from '@/modules/unit/schema'
import { UnitUserResource } from '@/modules/unit/services/UnitUserResource'
import { useUnitUserStore } from '@/modules/unitUser/store/unitUserStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Download, Loader2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

/**
 * Asignaciones de unidad (persona ↔ unidad). Vista de consulta y auditoría;
 * el alta/edición se hace desde el detalle de cada unidad (§6.3.4).
 */
export const UnitUserListPage = () => {
  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<IUnitUser>({
      store: useUnitUserStore,
      resource: UnitUserResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'start_date', direction: 'desc' }],
        includes: [{ relation: 'unit' }, { relation: 'user' }, { relation: 'unitRelationship' }],
      },
    })
  const { entities, pagination, isLoading } = useUnitUserStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Residentes por Unidad'
        description='Historial de asociaciones persona–unidad; gestiona cada una desde el detalle de la unidad'
        actions={
          <PermissionGuard permission='report.unit_user'>
            <Button
              variant='outline'
              onClick={() => onReport('UnitUser', 'xlsx', 'residentes-por-unidad')}
              disabled={isExporting}
              className='flex items-center gap-2'
            >
              {isExporting ? (
                <Loader2 className='h-4 w-4 animate-spin' />
              ) : (
                <Download className='h-4 w-4' />
              )}
              Descargar
            </Button>
          </PermissionGuard>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar por unidad o notas…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IUnitUser>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Unidad</TableHead>
                    <TableHead scope='col'>Persona</TableHead>
                    <TableHead scope='col'>Rol</TableHead>
                    <TableHead scope='col'>Titular de pago</TableHead>
                    <TableHead scope='col'>Inicio</TableHead>
                    <TableHead scope='col'>Fin</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(assoc) => (
                <TableRow key={assoc.id}>
                  <TableCell>
                    <Link
                      to={`/unit/${assoc.unit_id}`}
                      className='font-medium text-[var(--green-deep)] hover:underline'
                    >
                      {assoc.unit?.full_identifier ?? assoc.unit?.number ?? '—'}
                    </Link>
                  </TableCell>
                  <TableCell>{assoc.user?.name ?? '—'}</TableCell>
                  <TableCell>{assoc.unit_relationship?.name ?? '—'}</TableCell>
                  <TableCell>{assoc.is_billing_owner ? 'Sí' : 'No'}</TableCell>
                  <TableCell>
                    {assoc.start_date ? displayFormatDate(assoc.start_date, 'mediumDate') : '—'}
                  </TableCell>
                  <TableCell>
                    {assoc.end_date ? displayFormatDate(assoc.end_date, 'mediumDate') : '—'}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={assoc.end_date ? 'r' : 'g'}>
                      {assoc.end_date ? 'Histórica' : 'Activa'}
                    </StatusPill>
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(assoc) => (
                <Link
                  to={`/unit/${assoc.unit_id}`}
                  className='font-medium text-[var(--green-deep)] hover:underline'
                >
                  {assoc.unit?.full_identifier ?? assoc.unit?.number ?? '—'}
                </Link>
              )}
              renderCardFields={(assoc) => [
                { label: 'Persona', value: assoc.user?.name ?? '—' },
                { label: 'Rol', value: assoc.unit_relationship?.name ?? '—' },
                { label: 'Titular de pago', value: assoc.is_billing_owner ? 'Sí' : 'No' },
                {
                  label: 'Inicio',
                  value: assoc.start_date ? displayFormatDate(assoc.start_date, 'mediumDate') : '—',
                },
                {
                  label: 'Fin',
                  value: assoc.end_date ? displayFormatDate(assoc.end_date, 'mediumDate') : '—',
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={assoc.end_date ? 'r' : 'g'}>
                      {assoc.end_date ? 'Histórica' : 'Activa'}
                    </StatusPill>
                  ),
                },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
