import type { ICompany } from '@/modules/company/schema'
import { CompanyResource } from '@/modules/company/services/CompanyResource'
import { ApiError } from '@/shared/api/apiClient'
import { useQuery } from '@tanstack/react-query'

const QUERY_KEY = ['company', 'me'] as const

export function useCompanyMe() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => CompanyResource.me(),
    retry: (failureCount, err) => {
      const status = err instanceof ApiError ? err.status : undefined
      if (status === 404) return false
      return failureCount < 2
    },
  })

  return {
    company: (data as ICompany) ?? null,
    isLoading,
    error: error ? 'No se pudo cargar la empresa' : null,
    isNotFound: error instanceof ApiError && error.status === 404,
    refetch,
  }
}
