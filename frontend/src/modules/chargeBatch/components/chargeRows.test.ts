import { describe, expect, it } from 'vitest'
import { toChargeRows } from '@/modules/chargeBatch/components/chargeRows'

describe('toChargeRows', () => {
  it('devuelve [] ante entradas que no son array (evita el crash de la página de detalle)', () => {
    // Shapes que llegaban por la caché compartida de React Query (p. ej. el
    // { charges, total } de BatchUnitChargesTable) o por respuestas malformadas.
    const badInputs: unknown[] = [{ charges: [], total: 0 }, undefined, null, 'x', 42]
    for (const value of badInputs) {
      expect(toChargeRows(value as never)).toEqual([])
    }
  })

  it('excluye cargos cancelados y conserva el resto', () => {
    const rows = toChargeRows([
      { id: '1', expected_amount: 150000, paid_amount: 0, is_cancelled: false },
      { id: '2', expected_amount: 150000, paid_amount: 0, is_cancelled: true },
    ] as never)
    expect(rows).toHaveLength(1)
    expect(rows[0].charge.id).toBe('1')
  })
})
