import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { type IStimulationGroup } from '@/modules/stimulationGroup/schema'
import { StimulationGroupFormModal } from '@/modules/stimulationGroup/StimulationGroupFormModal'
import { StimulationGroupResource } from '@/modules/stimulationGroup/services/StimulationGroupResource'
import { useStimulationGroupStore } from '@/modules/stimulationGroup/store/stimulationGroupStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Plus, Trash2 } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

export function StimulationGroupListPage() {
  const navigate = useNavigate()

  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch, getList } = useBaseList<IStimulationGroup>({
    store: useStimulationGroupStore,
    resource: StimulationGroupResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [{ relation: 'stimulation_group_type' }, { relation: 'health_center' }],
    },
  })

  const { entities, pagination, isLoading } = useStimulationGroupStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useStimulationGroupStore((s) => s.isDeleting)

  const permissions = useMultiplePermissions({
    canCreate: 'create.stimulation_group',
    canDelete: 'delete.stimulation_group',
  })

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<IStimulationGroup | null>(null)
  const [toDelete, setToDelete] = useState<IStimulationGroup | null>(null)

  const handleDeleteConfirm = useCallback(async () => {
    if (!toDelete) return
    try {
      await onRemove(toDelete)
      setToDelete(null)
    } catch {
      // el toast global ya reporta el error
    }
  }, [toDelete, onRemove])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Grupos de Estimulación"
        description="Grupos de estimulación temprana por centro de salud"
        actions={
          <PermissionGuard permission="create.stimulation_group">
            <Button
              onClick={() => {
                setEditing(null)
                setFormOpen(true)
              }}
              className="flex items-center gap-2"
            >
              <Plus className="h-4 w-4" />
              Nuevo grupo
            </Button>
          </PermissionGuard>
        }
      />

      <section className="space-y-6">
        <SearchList paramsSearch={paramsSearch} onSearch={onSearch} onReset={onReset} placeholder="Buscar por nombre…" />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IStimulationGroup>
              rows={entities}
              breakpoint="lg"
              onRowClick={(group) => navigate(`/stimulation-group/${group.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Nombre</TableHead>
                    <TableHead scope="col">Tipo</TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Centro de salud
                    </TableHead>
                    <TableHead scope="col">Estado</TableHead>
                    {permissions.canDelete && <TableHead scope="col" className="text-right" />}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(group) => (
                <TableRow key={group.id} className="cursor-pointer">
                  <TableCell className="font-medium">{group.name}</TableCell>
                  <TableCell>{group.stimulation_group_type?.name ?? '—'}</TableCell>
                  <TableCell className="hidden md:table-cell">{group.health_center?.name ?? '—'}</TableCell>
                  <TableCell>
                    <StatusPill tone={group.is_active ? 'b' : 'n'}>{group.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
                  </TableCell>
                  {permissions.canDelete && (
                    <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setToDelete(group)}
                        aria-label={`Eliminar ${group.name}`}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(group) => group.name}
              renderCardFields={(group) => [
                { label: 'Tipo', value: group.stimulation_group_type?.name ?? '—' },
                { label: 'Centro de salud', value: group.health_center?.name ?? '—' },
                { label: 'Inicio', value: displayFormatDate(group.start_date, 'mediumDate') },
              ]}
              renderActions={
                permissions.canDelete
                  ? (group) => (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setToDelete(group)}
                        aria-label={`Eliminar ${group.name}`}
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

      <StimulationGroupFormModal
        isOpen={formOpen}
        mode={CrudMode.CREATE}
        group={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => void getList()}
      />

      <ConfirmDialog
        isOpen={Boolean(toDelete)}
        variant="delete"
        entityName={toDelete?.name}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setToDelete(null)}
      />
    </div>
  )
}
