import type { ApiError } from '@/shared/api/apiClient'

export function followUpErrorCode(error: unknown): string | undefined {
  if (!error || typeof error !== 'object') return undefined
  const apiError = error as ApiError
  const payload = apiError.payload as { code?: string } | undefined
  return payload?.code
}

export function followUpErrorMessage(error: unknown, fallback = 'Ocurrió un error inesperado.'): string {
  if (!error || typeof error !== 'object') return fallback
  const apiError = error as ApiError
  const payload = apiError.payload as { code?: string; message?: string } | undefined
  return payload?.message ?? apiError.message ?? fallback
}
