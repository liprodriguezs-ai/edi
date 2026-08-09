import type { IRolePermissionModule } from '@/modules/role/schema'
import { RoleResource } from '@/modules/role/services/RoleResource'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useState } from 'react'

export function useRolePermissions(roleId: string) {
  const queryClient = useQueryClient()
  const [localModules, setLocalModules] = useState<IRolePermissionModule[]>([])
  const [isInitialized, setIsInitialized] = useState(false)
  const [isSaving, setIsSaving] = useState(false)

  const { data: serverModules, isLoading: queryLoading, error } = useQuery({
    queryKey: ['role-permissions', roleId],
    queryFn: () => RoleResource.getPermissions(roleId),
  })

  useEffect(() => {
    if (serverModules) {
      setLocalModules(serverModules)
      setIsInitialized(true)
    }
  }, [serverModules])

  const isLoading = queryLoading || (!isInitialized && !error)

  const hasChanges = useMemo(() => {
    if (!serverModules || serverModules.length === 0) return false

    return serverModules.some((serverModule) => {
      const localModule = localModules.find((m) => m.id === serverModule.id)
      if (!localModule) return true

      return serverModule.permissions.some((sp) => {
        const lp = localModule.permissions.find((p) => p.uuid === sp.uuid)
        return !lp || lp.active !== sp.active
      })
    })
  }, [serverModules, localModules])

  const togglePermission = useCallback((moduleId: string, permissionUuid: string) => {
    setLocalModules((prev) =>
      prev.map((mod) => {
        if (mod.id !== moduleId) return mod
        return {
          ...mod,
          permissions: mod.permissions.map((perm) =>
            perm.uuid === permissionUuid ? { ...perm, active: !perm.active } : perm,
          ),
        }
      }),
    )
  }, [])

  const toggleModule = useCallback((moduleId: string) => {
    setLocalModules((prev) =>
      prev.map((mod) => {
        if (mod.id !== moduleId) return mod
        const allActive = mod.permissions.every((p) => p.active)
        return {
          ...mod,
          permissions: mod.permissions.map((perm) => ({ ...perm, active: !allActive })),
        }
      }),
    )
  }, [])

  const toggleColumn = useCallback((action: string) => {
    setLocalModules((prev) => {
      const allActiveInColumn = prev.every((mod) => {
        const perm = mod.permissions.find((p) => p.name.startsWith(`${action}.`))
        return !perm || perm.active
      })
      return prev.map((mod) => ({
        ...mod,
        permissions: mod.permissions.map((perm) =>
          perm.name.startsWith(`${action}.`) ? { ...perm, active: !allActiveInColumn } : perm,
        ),
      }))
    })
  }, [])

  const selectAll = useCallback(() => {
    setLocalModules((prev) =>
      prev.map((mod) => ({
        ...mod,
        permissions: mod.permissions.map((perm) => ({ ...perm, active: true })),
      })),
    )
  }, [])

  const deselectAll = useCallback(() => {
    setLocalModules((prev) =>
      prev.map((mod) => ({
        ...mod,
        permissions: mod.permissions.map((perm) => ({ ...perm, active: false })),
      })),
    )
  }, [])

  const savePermissions = useCallback(async () => {
    setIsSaving(true)
    try {
      const payload: Record<string, Record<string, { uuid: string; active: boolean }>> = {}

      for (const mod of localModules) {
        payload[mod.id] = {}
        for (const perm of mod.permissions) {
          const action = perm.name.split('.')[0]
          payload[mod.id][action] = { uuid: perm.uuid, active: perm.active }
        }
      }

      await RoleResource.setPermissions(roleId, payload)
      queryClient.invalidateQueries({ queryKey: ['role-permissions', roleId] })
    } finally {
      setIsSaving(false)
    }
  }, [localModules, roleId, queryClient])

  return {
    modules: localModules,
    isLoading,
    isSaving,
    hasChanges,
    error: error ? 'Error al cargar los permisos' : null,
    togglePermission,
    toggleModule,
    toggleColumn,
    selectAll,
    deselectAll,
    savePermissions,
  }
}
