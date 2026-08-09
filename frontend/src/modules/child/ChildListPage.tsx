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
import { childFullName, type IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { useChildStore } from '@/modules/child/store/childStore'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Plus, Trash2 } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

/**
 * REQ-XC-006 — no `useCatalogs` call here (list scope): search is by name/CURP
 * only (REQ-CH-001 filter, backend searchableBy), no eager catalog dumps.
 */
export function ChildListPage() {
  const navigate = useNavigate()

  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useBaseList<IChild>({
    store: useChildStore,
    resource: ChildResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'last_name_paternal', direction: 'asc' }],
      includes: [{ relation: 'sex' }, { relation: 'health_center' }],
    },
  })

  const { entities, pagination, isLoading } = useChildStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useChildStore((s) => s.isDeleting)

  const permissions = useMultiplePermissions({
    canCreate: 'create.child',
    canDelete: 'delete.child',
  })

  const [childToDelete, setChildToDelete] = useState<IChild | null>(null)

  const handleDeleteConfirm = useCallback(async () => {
    if (!childToDelete) return
    try {
      await onRemove(childToDelete)
      setChildToDelete(null)
    } catch {
      // el toast global ya reporta el error
    }
  }, [childToDelete, onRemove])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Niños"
        description="Expediente de niños registrados en tu organización"
        actions={
          <PermissionGuard permission="create.child">
            <Button onClick={() => navigate('/child/new')} className="flex items-center gap-2">
              <Plus className="h-4 w-4" />
              Nuevo niño
            </Button>
          </PermissionGuard>
        }
      />

      <section className="space-y-6">
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder="Buscar por nombre o CURP…"
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IChild>
              rows={entities}
              breakpoint="lg"
              onRowClick={(child) => navigate(`/child/${child.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Nombre</TableHead>
                    <TableHead scope="col">CURP</TableHead>
                    <TableHead scope="col">Sexo</TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Nacimiento
                    </TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Centro de salud
                    </TableHead>
                    {permissions.canDelete && <TableHead scope="col" className="text-right" />}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(child) => (
                <TableRow key={child.id} className="cursor-pointer">
                  <TableCell className="font-medium">
                    <span className="inline-flex items-center gap-1.5">
                      {childFullName(child)}
                      {child.curp_mismatch && (
                        <span
                          className="text-xs text-amber-600"
                          title="El CURP no coincide con la fecha de nacimiento o sexo capturados"
                        >
                          ⚠︎
                        </span>
                      )}
                    </span>
                  </TableCell>
                  <TableCell>{child.curp || '—'}</TableCell>
                  <TableCell>{child.sex?.name ?? '—'}</TableCell>
                  <TableCell className="hidden md:table-cell">
                    {displayFormatDate(child.birth_date, 'mediumDate')}
                  </TableCell>
                  <TableCell className="hidden md:table-cell">{child.health_center?.name ?? '—'}</TableCell>
                  {permissions.canDelete && (
                    <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setChildToDelete(child)}
                        aria-label={`Eliminar ${childFullName(child)}`}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(child) => childFullName(child)}
              renderCardFields={(child) => [
                { label: 'CURP', value: child.curp || '—' },
                { label: 'Sexo', value: child.sex?.name ?? '—' },
                { label: 'Nacimiento', value: displayFormatDate(child.birth_date, 'mediumDate') },
                { label: 'Centro de salud', value: child.health_center?.name ?? '—' },
              ]}
              renderActions={
                permissions.canDelete
                  ? (child) => (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setChildToDelete(child)}
                        aria-label={`Eliminar ${childFullName(child)}`}
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
        isOpen={Boolean(childToDelete)}
        variant="delete"
        entityName={childToDelete ? childFullName(childToDelete) : undefined}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setChildToDelete(null)}
      />
    </div>
  )
}
