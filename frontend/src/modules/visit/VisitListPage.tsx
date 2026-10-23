import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { childFullName } from '@/modules/child/schema'
import { ApplyVisitPickerDialog } from '@/modules/screening/components/ApplyVisitPickerDialog'
import { type IVisit } from '@/modules/visit/schema'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import { useVisitStore } from '@/modules/visit/store/visitStore'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ClipboardCheck } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

/**
 * Bitácora global de visitas (worklist). Las visitas se REGISTRAN desde el
 * detalle del niño (VisitFormModal, child pre-bound, D12) — esta página es de
 * consulta/histórico.
 */
export function VisitListPage() {
  const navigate = useNavigate()

  const { onPageChanged, onSearch, onReset, paramsSearch } = useBaseList<IVisit>({
    store: useVisitStore,
    resource: VisitResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'visit_date', direction: 'desc' }],
      includes: [
        { relation: 'child' },
        { relation: 'health_center' },
        { relation: 'practitioner' },
        { relation: 'practitioner.user' },
      ],
    },
  })

  const { entities, pagination, isLoading } = useVisitStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  // edi-screening-ui (REQ-SUI-002) — row action to apply a screening for
  // this visit. Navigation only; the wizard route itself resolves
  // VISIT_ALREADY_SCREENED defensively.
  const [chosenGroupVisit, setChosenGroupVisit] = useState<IVisit | null>(null)

  const handleApplyScreening = useCallback(
    (visit: IVisit) => navigate(`/screening/apply/${visit.id}`),
    [navigate]
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Visitas'
        description='Historial de visitas registradas en tu organización'
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar por folio…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IVisit>
              rows={entities}
              breakpoint='lg'
              onRowClick={(visit) => visit.child?.id && navigate(`/child/${visit.child.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Folio</TableHead>
                    <TableHead scope='col'>Niño</TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Centro de salud
                    </TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Profesional
                    </TableHead>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col' className='text-right' />
                  </TableRow>
                </TableHeader>
              }
              renderRow={(visit) => (
                <TableRow key={visit.id} className='cursor-pointer'>
                  <TableCell className='font-mono font-medium'>{visit.receipt_number}</TableCell>
                  <TableCell>{visit.child ? childFullName(visit.child) : '—'}</TableCell>
                  <TableCell className='hidden md:table-cell'>
                    {visit.health_center?.name ?? '—'}
                  </TableCell>
                  <TableCell className='hidden md:table-cell'>
                    {visit.practitioner?.user?.name ?? '—'}
                  </TableCell>
                  <TableCell className='font-mono tabular-nums'>
                    {displayFormatDate(visit.visit_date, 'mediumDate')}
                  </TableCell>
                  {/* The row navigates to the child; the action must not bubble into it. */}
                  <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                    <PermissionGuard permission='create.screening'>
                      <Button variant='ghost' size='sm' onClick={() => handleApplyScreening(visit)}>
                        <ClipboardCheck className='h-4 w-4' />
                        Prueba EDI
                      </Button>
                      <Button variant='ghost' size='sm' onClick={() => setChosenGroupVisit(visit)}>
                        A elección
                      </Button>
                    </PermissionGuard>
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(visit) => visit.receipt_number}
              renderCardFields={(visit) => [
                { label: 'Niño', value: visit.child ? childFullName(visit.child) : '—' },
                { label: 'Centro de salud', value: visit.health_center?.name ?? '—' },
                { label: 'Profesional', value: visit.practitioner?.user?.name ?? '—' },
                {
                  label: 'Fecha',
                  value: (
                    <span className='font-mono tabular-nums'>
                      {displayFormatDate(visit.visit_date, 'mediumDate')}
                    </span>
                  ),
                },
              ]}
              renderActions={(visit) => (
                <PermissionGuard permission='create.screening'>
                  <Button variant='ghost' size='icon' onClick={() => handleApplyScreening(visit)}>
                    <ClipboardCheck className='h-4 w-4' />
                  </Button>
                </PermissionGuard>
              )}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
      <ApplyVisitPickerDialog
        isOpen={chosenGroupVisit !== null}
        onClose={() => setChosenGroupVisit(null)}
        visit={chosenGroupVisit}
        initialMode='chosen'
      />
    </div>
  )
}
