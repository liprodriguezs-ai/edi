import type { ApiError } from '@/shared/api/apiClient'

/**
 * D12 — code → Spanish message OVERRIDE. The server already sends a Spanish
 * `message` for every domain error (ScreeningDomainException), so this map
 * only exists for the rare case a code needs UI-specific phrasing; every
 * other code falls back to the server's own message.
 */
const CODE_OVERRIDES: Partial<Record<string, string>> = {}

export function screeningErrorCode(error: unknown): string | undefined {
  if (!error || typeof error !== 'object') return undefined
  const apiError = error as ApiError
  const payload = apiError.payload as { code?: string } | undefined
  return payload?.code
}

export function screeningErrorMessage(error: unknown, fallback = 'Ocurrió un error inesperado.'): string {
  if (!error || typeof error !== 'object') return fallback
  const apiError = error as ApiError
  const payload = apiError.payload as { code?: string; message?: string } | undefined
  const code = payload?.code
  if (code && CODE_OVERRIDES[code]) return CODE_OVERRIDES[code] as string
  return payload?.message ?? apiError.message ?? fallback
}

export function isScreeningErrorCode(error: unknown, code: string): boolean {
  return screeningErrorCode(error) === code
}
