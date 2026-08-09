import { useQuery } from '@tanstack/react-query'

import { isNotFoundError } from '@/shared/api/apiClient'

export interface IUseEntityDetailOptions<TEntity> {
  queryKey: readonly unknown[]
  queryFn: () => Promise<TEntity>
  /** Defaults to true. Pass Boolean(id) when the id comes from the URL. */
  enabled?: boolean
  staleTime?: number
}

export interface IUseEntityDetailResult<TEntity> {
  entity: TEntity | undefined
  isLoading: boolean
  isError: boolean
  /** True once the main query resolved — use it as `enabled` for dependent queries. */
  isSuccess: boolean
  /** ApiError 404: the record does not exist or the user has no access to it. */
  notFound: boolean
  error: Error | null
  refetch: () => void
}

/**
 * Standard fetch-by-id for detail/edit pages. Thin wrapper over useQuery that
 * surfaces the error state (no more infinite loading on 404) and exposes
 * `isSuccess` so dependent queries only fire after the entity actually loaded.
 */
export function useEntityDetail<TEntity>(
  options: IUseEntityDetailOptions<TEntity>
): IUseEntityDetailResult<TEntity> {
  const { data, isLoading, isError, isSuccess, error, refetch } = useQuery({
    queryKey: options.queryKey,
    queryFn: options.queryFn,
    enabled: options.enabled ?? true,
    staleTime: options.staleTime,
  })

  return {
    entity: data,
    isLoading,
    isError,
    isSuccess,
    notFound: isError && isNotFoundError(error),
    error,
    refetch: () => void refetch(),
  }
}
