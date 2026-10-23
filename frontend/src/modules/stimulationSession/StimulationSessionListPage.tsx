import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { childFullName } from '@/modules/child/schema'
import { type IStimulationSession } from '@/modules/stimulationSession/schema'
import { StimulationSessionResource } from '@/modules/stimulationSession/services/StimulationSessionResource'
import { useStimulationSessionStore } from '@/modules/stimulationSession/store/stimulationSessionStore'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Plus, Trash2 } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

/**
 * REQ-XC-005 — no `useCatalogs` here (list scope); catalogs load only when
 * the form page opens.
 */
export function StimulationSessionListPage() {
  const navigate = useNavigate()

  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useBaseList<IStimulationSession>({
    store: useStimulationSessionStore,
    resource: StimulationSessionResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'session_date', direction: 'desc' }],
      includes: [{ relation: 'child' }, { relation: 'stimulation_group' }, { relation: 'practitioner' }],
    },
  })

  const { entities, pagination, isLoading } = useStimulationSessionStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useStimulationSessionStore((s) => s.isDeleting)

  const permissions = useMultiplePermissions({
    canCreate: 'create.stimulation_session',
    canDelete: 'delete.stimulation_session',
  })

  const [toDelete, setToDelete] = useState<IStimulationSession | null>(null)

  const handleDeleteConfirm = useCallback(async () => {
    if (!toDelete) return
    try {
      await onRemove(toDelete)
      setToDelete(null)
    } catch {
      // el toast global ya reporta el error
    }
  }, [toDelete, onRemove])

  const subjectLabel = (session: IStimulationSession) =>
    session.child ? childFullName(session.child) : (session.stimulation_group?.name ?? '—')

  return (
    <div className="space-y-6">
      <PageHeader
        title="Sesiones de Estimulación"
        description="Sesiones individuales y grupales de estimulación temprana"
        actions={
          <PermissionGuard permission="create.stimulation_session">
            <Button onClick={() => navigate('/stimulation-session/new')} className="flex items-center gap-2">
              <Plus className="h-4 w-4" />
              Nueva sesión
            </Button>
          </PermissionGuard>
        }
      />

      <section className="space-y-6">
        <SearchList paramsSearch={paramsSearch} onSearch={onSearch} onReset={onReset} placeholder="Buscar…" />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IStimulationSession>
              rows={entities}
              breakpoint="lg"
              onRowClick={(session) => navigate(`/stimulation-session/${session.id}/edit`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Sujeto</TableHead>
                    <TableHead scope="col">Fecha</TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Profesional
                    </TableHead>
                    {permissions.canDelete && <TableHead scope="col" className="text-right" />}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(session) => (
                <TableRow key={session.id} className="cursor-pointer">
                  <TableCell className="font-medium">{subjectLabel(session)}</TableCell>
                  <TableCell>{displayFormatDate(session.session_date, 'mediumDate')}</TableCell>
                  <TableCell className="hidden md:table-cell">{session.practitioner?.user?.name ?? '—'}</TableCell>
                  {permissions.canDelete && (
                    <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setToDelete(session)}
                        aria-label={`Eliminar sesión de ${subjectLabel(session)}`}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(session) => subjectLabel(session)}
              renderCardFields={(session) => [
                { label: 'Fecha', value: displayFormatDate(session.session_date, 'mediumDate') },
                { label: 'Profesional', value: session.practitioner?.user?.name ?? '—' },
              ]}
              renderActions={
                permissions.canDelete
                  ? (session) => (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setToDelete(session)}
                        aria-label={`Eliminar sesión de ${subjectLabel(session)}`}
                      >
                        <Trash2 className="size-4" />
                      </Button>
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
        variant="delete"
        entityName={toDelete ? subjectLabel(toDelete) : undefined}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setToDelete(null)}
      />
    </div>
  )
}
