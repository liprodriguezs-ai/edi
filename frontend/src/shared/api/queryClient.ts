import { QueryClient } from '@tanstack/react-query'

import { ApiError } from '@/shared/api/apiClient'

const MAX_QUERY_RETRIES = 1

/**
 * Never retries 4xx responses (404/403/422 do not change on retry, so retrying
 * only wastes requests). Network errors and 5xx get a single retry.
 */
export function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  if (
    error instanceof ApiError &&
    error.status !== undefined &&
    error.status >= 400 &&
    error.status < 500
  ) {
    return false
  }
  return failureCount < MAX_QUERY_RETRIES
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      staleTime: 1000 * 30,
      retry: shouldRetryQuery,
    },
  },
})
