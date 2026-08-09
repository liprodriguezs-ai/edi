import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'
import { Skeleton } from 'boneyard-js/react'
import { useRolePermissions } from '@/modules/role/hooks/useRolePermissions'
import type { IRolePermissionModule } from '@/modules/role/schema'
import { Loader2, Save, ShieldCheck } from 'lucide-react'
import { useCallback, useMemo } from 'react'
import { toast } from '@/shared/hooks/useToast'

const ACTION_COLUMNS = [
  { key: 'read', label: 'Lectura' },
  { key: 'create', label: 'Creación' },
  { key: 'update', label: 'Edición' },
  { key: 'delete', label: 'Eliminar' },
  { key: 'restore', label: 'Restaurar' },
  { key: 'report', label: 'Reporte' },
] as const

const COLUMN_COUNT = ACTION_COLUMNS.length + 1

interface RolePermissionsEditorProps {
  roleId: string
}

function groupModulesByGroup(modules: IRolePermissionModule[]) {
  const groups: { name: string; modules: IRolePermissionModule[] }[] = []
  const groupMap = new Map<string, IRolePermissionModule[]>()

  for (const mod of modules) {
    const groupName = mod.group || 'Otros'
    if (!groupMap.has(groupName)) {
      groupMap.set(groupName, [])
      groups.push({ name: groupName, modules: groupMap.get(groupName)! })
    }
    groupMap.get(groupName)!.push(mod)
  }

  return groups
}

export function RolePermissionsEditor({ roleId }: RolePermissionsEditorProps) {
  const {
    modules,
    isLoading,
    isSaving,
    hasChanges,
    error,
    togglePermission,
    toggleModule,
    toggleColumn,
    selectAll,
    deselectAll,
    savePermissions,
  } = useRolePermissions(roleId)

  const handleSave = useCallback(async () => {
    try {
      await savePermissions()
      toast.success('Permisos actualizados correctamente')
    } catch {
      toast.error('Error al guardar los permisos')
    }
  }, [savePermissions])

  const groupedModules = useMemo(() => groupModulesByGroup(modules), [modules])

  const columnStates = useMemo(() => {
    const states: Record<string, { all: boolean; some: boolean }> = {}
    for (const col of ACTION_COLUMNS) {
      const perms = modules.flatMap((m) =>
        m.permissions.filter((p) => p.name.startsWith(`${col.key}.`))
      )
      const activeCount = perms.filter((p) => p.active).length
      states[col.key] = {
        all: perms.length > 0 && activeCount === perms.length,
        some: activeCount > 0 && activeCount < perms.length,
      }
    }
    return states
  }, [modules])

  const allGlobalActive = useMemo(
    () => modules.length > 0 && modules.every((m) => m.permissions.every((p) => p.active)),
    [modules]
  )
  const someGlobalActive = useMemo(
    () => modules.some((m) => m.permissions.some((p) => p.active)) && !allGlobalActive,
    [modules, allGlobalActive]
  )

  if (error) {
    return (
      <div className='flex flex-col items-center justify-center gap-2 py-12'>
        <p className='text-muted-foreground text-sm'>{error}</p>
      </div>
    )
  }

  if (modules.length === 0 && !isLoading) {
    return (
      <div className='flex flex-col items-center justify-center gap-2 py-12'>
        <ShieldCheck className='text-muted-foreground h-10 w-10' />
        <p className='text-muted-foreground text-sm'>No hay permisos disponibles</p>
      </div>
    )
  }

  return (
    <Skeleton name='role-permissions-editor' loading={isLoading}>
      <div className='flex flex-col'>
        <div className='overflow-x-auto rounded-md border'>
          <div className='max-h-[calc(100vh-220px)] overflow-y-auto'>
            <table className='w-full min-w-[700px]'>
              <thead className='sticky top-0 z-10 bg-card shadow-[0_1px_0_0] shadow-border'>
                <tr>
                  <th className='px-4 py-3 text-left text-sm font-medium text-muted-foreground'>
                    <div className='flex items-center gap-2.5'>
                      <Checkbox
                        checked={
                          allGlobalActive ? true : someGlobalActive ? 'indeterminate' : false
                        }
                        onCheckedChange={() => (allGlobalActive ? deselectAll() : selectAll())}
                      />
                      <span>Módulo</span>
                    </div>
                  </th>
                  {ACTION_COLUMNS.map((col) => (
                    <th
                      key={col.key}
                      className='w-[100px] px-2 py-3 text-center text-sm font-medium text-muted-foreground'
                    >
                      <div className='flex flex-col items-center gap-1'>
                        <span className='text-xs'>{col.label}</span>
                        <Checkbox
                          checked={
                            columnStates[col.key]?.all
                              ? true
                              : columnStates[col.key]?.some
                                ? 'indeterminate'
                                : false
                          }
                          onCheckedChange={() => toggleColumn(col.key)}
                        />
                      </div>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {groupedModules.map((group) => (
                  <GroupSection
                    key={group.name}
                    groupName={group.name}
                    modules={group.modules}
                    onTogglePermission={togglePermission}
                    onToggleModule={toggleModule}
                  />
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className='sticky bottom-0 z-10 -mx-4 mt-4 border-t bg-background px-4 py-3 lg:-mx-6 lg:px-6'>
          <div className='flex items-center justify-between gap-4'>
            <div className='flex items-center gap-2'>
              <Button variant='outline' size='sm' onClick={selectAll}>
                Marcar todas
              </Button>
              <Button variant='outline' size='sm' onClick={deselectAll}>
                Desmarcar todas
              </Button>
            </div>
            <Button onClick={handleSave} disabled={!hasChanges || isSaving}>
              {isSaving ? (
                <Loader2 className='mr-2 h-4 w-4 animate-spin' />
              ) : (
                <Save className='mr-2 h-4 w-4' />
              )}
              Guardar
            </Button>
          </div>
        </div>
      </div>
    </Skeleton>
  )
}

function GroupSection({
  groupName,
  modules,
  onTogglePermission,
  onToggleModule,
}: {
  groupName: string
  modules: IRolePermissionModule[]
  onTogglePermission: (_moduleId: string, _permissionUuid: string) => void
  onToggleModule: (_moduleId: string) => void
}) {
  return (
    <>
      <tr>
        <td
          colSpan={COLUMN_COUNT}
          className='bg-muted/40 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground'
        >
          {groupName}
        </td>
      </tr>
      {modules.map((mod, idx) => {
        const allActive = mod.permissions.length > 0 && mod.permissions.every((p) => p.active)
        const someActive = mod.permissions.some((p) => p.active) && !allActive
        const isLast = idx === modules.length - 1

        return (
          <tr
            key={mod.id}
            className={cn(
              'transition-colors hover:bg-muted/20',
              !isLast && 'border-b border-border/40'
            )}
          >
            <td className='px-4 py-2.5'>
              <div className='flex items-center gap-2.5'>
                <Checkbox
                  checked={allActive ? true : someActive ? 'indeterminate' : false}
                  onCheckedChange={() => onToggleModule(mod.id)}
                />
                <span className='text-sm'>{mod.name}</span>
              </div>
            </td>
            {ACTION_COLUMNS.map((col) => {
              const perm = mod.permissions.find((p) => p.name.startsWith(`${col.key}.`))

              if (!perm) {
                return (
                  <td key={col.key} className='px-2 py-2.5 text-center'>
                    <span className='text-muted-foreground/20'>—</span>
                  </td>
                )
              }

              return (
                <td key={col.key} className='px-2 py-2.5 text-center'>
                  <Checkbox
                    checked={perm.active}
                    onCheckedChange={() => onTogglePermission(mod.id, perm.uuid)}
                  />
                </td>
              )
            })}
          </tr>
        )
      })}
    </>
  )
}
