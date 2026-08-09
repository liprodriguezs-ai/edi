import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { childFullName } from '@/modules/child/schema'
import { type IVisit } from '@/modules/visit/schema'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import { useVisitStore } from '@/modules/visit/store/visitStore'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { displayFormatDate } from '@/shared/utils/dateHelper'
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
      includes: [{ relation: 'child' }, { relation: 'health_center' }, { relation: 'practitioner' }],
    },
  })

  const { entities, pagination, isLoading } = useVisitStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  return (
    <div className="space-y-6">
      <PageHeader title="Visitas" description="Historial de visitas registradas en tu organización" />

      <section className="space-y-6">
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder="Buscar por folio…"
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IVisit>
              rows={entities}
              breakpoint="lg"
              onRowClick={(visit) => visit.child?.id && navigate(`/child/${visit.child.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Folio</TableHead>
                    <TableHead scope="col">Niño</TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Centro de salud
                    </TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Profesional
                    </TableHead>
                    <TableHead scope="col">Fecha</TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(visit) => (
                <TableRow key={visit.id} className="cursor-pointer">
                  <TableCell className="font-medium">{visit.receipt_number}</TableCell>
                  <TableCell>{visit.child ? childFullName(visit.child) : '—'}</TableCell>
                  <TableCell className="hidden md:table-cell">{visit.health_center?.name ?? '—'}</TableCell>
                  <TableCell className="hidden md:table-cell">
                    {visit.practitioner?.user?.name ?? '—'}
                  </TableCell>
                  <TableCell>{displayFormatDate(visit.visit_date, 'mediumDate')}</TableCell>
                </TableRow>
              )}
              renderCardTitle={(visit) => visit.receipt_number}
              renderCardFields={(visit) => [
                { label: 'Niño', value: visit.child ? childFullName(visit.child) : '—' },
                { label: 'Centro de salud', value: visit.health_center?.name ?? '—' },
                { label: 'Profesional', value: visit.practitioner?.user?.name ?? '—' },
                { label: 'Fecha', value: displayFormatDate(visit.visit_date, 'mediumDate') },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
