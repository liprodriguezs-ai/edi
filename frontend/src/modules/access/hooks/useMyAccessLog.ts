import type { IAccessLog } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { useQuery } from '@tanstack/react-query'

/** Bitácora de accesos de las unidades del residente (§14.6), paginada. */
export function useMyAccessLog(page: number) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['access', 'my-log', page],
    queryFn: () => AccessLogResource.myLog(page),
    staleTime: 60_000,
  })

  return {
    logs: (data?.data ?? []) as IAccessLog[],
    lastPage: data?.last_page ?? 1,
    isLoading,
    isError,
    error,
  }
}
