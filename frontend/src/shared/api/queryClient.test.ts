import { ApiError } from '@/shared/api/apiClient'
import { shouldRetryQuery } from '@/shared/api/queryClient'
import { describe, expect, it } from 'vitest'

describe('shouldRetryQuery', () => {
  it('never retries a 404', () => {
    const error = new ApiError('No encontrado', 404)
    expect(shouldRetryQuery(0, error)).toBe(false)
    expect(shouldRetryQuery(1, error)).toBe(false)
  })

  it('never retries other 4xx responses', () => {
    expect(shouldRetryQuery(0, new ApiError('Prohibido', 403))).toBe(false)
    expect(shouldRetryQuery(0, new ApiError('Inválido', 422))).toBe(false)
  })

  it('retries a 5xx once', () => {
    const error = new ApiError('Error interno', 500)
    expect(shouldRetryQuery(0, error)).toBe(true)
    expect(shouldRetryQuery(1, error)).toBe(false)
  })

  it('retries a network error (no status) once', () => {
    expect(shouldRetryQuery(0, new Error('Network Error'))).toBe(true)
    expect(shouldRetryQuery(1, new Error('Network Error'))).toBe(false)
  })

  it('retries an ApiError without status once', () => {
    const error = new ApiError('Sin conexión')
    expect(shouldRetryQuery(0, error)).toBe(true)
    expect(shouldRetryQuery(1, error)).toBe(false)
  })
})
