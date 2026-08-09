import { randomId } from '@/shared/utils/randomId'
import { afterEach, describe, expect, it, vi } from 'vitest'

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('randomId', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('devuelve un UUID v4 válido', () => {
    expect(randomId()).toMatch(UUID_V4)
  })

  it('no repite ids', () => {
    const ids = new Set(Array.from({ length: 200 }, () => randomId()))
    expect(ids.size).toBe(200)
  })

  /**
   * El caso que rompió registro y checkout en producción-de-mentira: servida por IP
   * sobre HTTP el contexto NO es seguro y `crypto.randomUUID` simplemente no existe.
   * Llamarla lanzaba TypeError, el error se tragaba en un catch y el usuario veía
   * que su clic no hacía nada.
   */
  it('funciona cuando crypto.randomUUID no existe (contexto no seguro)', () => {
    vi.stubGlobal('crypto', { getRandomValues: globalThis.crypto.getRandomValues.bind(globalThis.crypto) })

    expect(() => randomId()).not.toThrow()
    expect(randomId()).toMatch(UUID_V4)
  })

  it('funciona aunque tampoco haya getRandomValues', () => {
    vi.stubGlobal('crypto', {})

    expect(() => randomId()).not.toThrow()
    expect(randomId()).toMatch(UUID_V4)
  })
})
