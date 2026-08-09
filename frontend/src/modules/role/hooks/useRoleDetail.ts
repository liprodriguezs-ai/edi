import type { IRole } from '@/modules/role/schema'
import { RoleResource } from '@/modules/role/services/RoleResource'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'

export function useRoleDetail(roleId: string) {
  const queryClient = useQueryClient()

  const { entity, isLoading, isError, notFound } = useEntityDetail<IRole>({
    queryKey: ['role-detail', roleId],
    queryFn: () => RoleResource.show(roleId),
    enabled: Boolean(roleId),
  })

  const refresh = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['role-detail', roleId] })
  }, [queryClient, roleId])

  return {
    role: entity ?? null,
    isLoading,
    error: isError ? 'Error al cargar el rol' : null,
    notFound,
    refresh,
  }
}
