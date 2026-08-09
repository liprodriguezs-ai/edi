import { describe, expect, it } from 'vitest'
import {
  AMOUNT_MODE,
  PERIODICITY,
  buildPolicyTargetSelector,
  chargePolicyFormSchema,
  type ChargePolicyFormValues,
} from '@/modules/chargePolicy/schema'
import {
  buildPolicyCreatePayload,
  buildPolicyUpdatePayload,
} from '@/modules/chargePolicy/buildPolicyPayload'

/** Valores base válidos: política recurrente, modo Rango coherente, ventana abierta. */
function baseValues(overrides: Partial<ChargePolicyFormValues> = {}): ChargePolicyFormValues {
  return {
    charge_concept_id: 'concept-1',
    name: 'Mantenimiento rango',
    amount_mode_id: AMOUNT_MODE.RANGE,
    fixed_amount: null,
    min_amount: 200,
    ideal_amount: 350,
    max_amount: 500,
    prorate_total_amount: null,
    grace_days: 5,
    periodicity_id: PERIODICITY.MONTHLY,
    valid_from: '2026-01-01',
    valid_to: '2026-12-31',
    is_active: true,
    target_type: 'all',
    target_unit_type_ids: [],
    target_tower_ids: [],
    target_floor: '',
    target_unit_ids: [],
    target_feature_ids: [],
    target_unit_id: undefined,
    issued_at: '',
    due_date: '',
    ...overrides,
  }
}

function issueOn(values: ChargePolicyFormValues, path: string): string | undefined {
  const result = chargePolicyFormSchema.safeParse(values)
  if (result.success) return undefined
  return result.error.issues.find((i) => i.path[0] === path)?.message
}

describe('chargePolicyFormSchema — modo Rango', () => {
  it('acepta un rango coherente min ≤ ideal ≤ max', () => {
    expect(chargePolicyFormSchema.safeParse(baseValues()).success).toBe(true)
  })

  it('rechaza min > ideal con mensaje en español', () => {
    expect(
      issueOn(baseValues({ min_amount: 500, ideal_amount: 350, max_amount: 600 }), 'min_amount')
    ).toBe('El mínimo no puede ser mayor al ideal')
  })

  it('rechaza ideal > max con mensaje en español', () => {
    expect(
      issueOn(baseValues({ min_amount: 100, ideal_amount: 999, max_amount: 100 }), 'ideal_amount')
    ).toBe('El ideal no puede ser mayor al máximo')
  })

  it('exige monto fijo en modo Fijo obligatorio', () => {
    expect(
      issueOn(
        baseValues({ amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED, fixed_amount: null }),
        'fixed_amount'
      )
    ).toBe('El monto fijo es requerido')
  })

  it('exige total a prorratear en modo Por indiviso', () => {
    expect(
      issueOn(
        baseValues({ amount_mode_id: AMOUNT_MODE.BY_SHARE, prorate_total_amount: null }),
        'prorate_total_amount'
      )
    ).toBe('El monto total a prorratear es requerido')
  })
})

describe('chargePolicyFormSchema — periodicidad recurrente (no eventual)', () => {
  it('exige vigencia inicial', () => {
    expect(issueOn(baseValues({ valid_from: '' }), 'valid_from')).toBe(
      'La vigencia inicial es requerida'
    )
  })

  it('exige vigencia final', () => {
    expect(issueOn(baseValues({ valid_to: '' }), 'valid_to')).toBe('La vigencia final es requerida')
  })

  it('rechaza valid_to < valid_from', () => {
    expect(
      issueOn(baseValues({ valid_from: '2026-06-01', valid_to: '2026-05-01' }), 'valid_to')
    ).toBe("La fecha 'Hasta' debe ser posterior o igual a 'Vigente desde'.")
  })

  it('NO exige fechas del cobro (issued_at/due_date) en periodicidad recurrente', () => {
    expect(
      chargePolicyFormSchema.safeParse(baseValues({ issued_at: '', due_date: '' })).success
    ).toBe(true)
  })

  it('exige al menos una torre en alcance by_tower también en periodicidad recurrente', () => {
    expect(
      issueOn(baseValues({ target_type: 'by_tower', target_tower_ids: [] }), 'target_tower_ids')
    ).toBe('Selecciona al menos una torre')
  })

  it('exige al menos una sección en alcance by_section también en periodicidad recurrente', () => {
    expect(
      issueOn(
        baseValues({ target_type: 'by_section', target_section_ids: [] }),
        'target_section_ids'
      )
    ).toBe('Selecciona al menos una sección')
  })

  it('exige al menos una unidad en alcance manual también en periodicidad recurrente', () => {
    expect(
      issueOn(baseValues({ target_type: 'manual', target_unit_ids: [] }), 'target_unit_ids')
    ).toBe('Selecciona al menos una unidad')
  })

  it('acepta alcance "all" sin selector en periodicidad recurrente', () => {
    expect(chargePolicyFormSchema.safeParse(baseValues({ target_type: 'all' })).success).toBe(true)
  })
})

describe('chargePolicyFormSchema — periodicidad EVENTUAL (cobro directo)', () => {
  // Eventual = cobro único: la validación de fechas se basa en periodicity_id === EVENTUAL.
  function eventualValues(overrides: Partial<ChargePolicyFormValues> = {}): ChargePolicyFormValues {
    return baseValues({
      amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED,
      fixed_amount: 1500,
      min_amount: null,
      ideal_amount: null,
      max_amount: null,
      periodicity_id: PERIODICITY.EVENTUAL,
      valid_from: '',
      valid_to: '',
      target_type: 'all',
      issued_at: '2026-03-01',
      due_date: '2026-03-15',
      ...overrides,
    })
  }

  it('acepta un cobro eventual válido sin vigencia', () => {
    expect(chargePolicyFormSchema.safeParse(eventualValues()).success).toBe(true)
  })

  it('exige fecha de generación', () => {
    expect(issueOn(eventualValues({ issued_at: '' }), 'issued_at')).toBe(
      'La fecha de generación es requerida'
    )
  })

  it('exige fecha de vencimiento', () => {
    expect(issueOn(eventualValues({ due_date: '' }), 'due_date')).toBe(
      'La fecha de vencimiento es requerida'
    )
  })

  it('rechaza due_date < issued_at', () => {
    expect(
      issueOn(eventualValues({ issued_at: '2026-03-15', due_date: '2026-03-01' }), 'due_date')
    ).toBe('Debe ser posterior o igual a la generación')
  })

  it('exige al menos una torre en alcance by_tower', () => {
    expect(
      issueOn(eventualValues({ target_type: 'by_tower', target_tower_ids: [] }), 'target_tower_ids')
    ).toBe('Selecciona al menos una torre')
  })

  it('NO exige vigencia (valid_from/valid_to) en periodicidad eventual', () => {
    expect(
      chargePolicyFormSchema.safeParse(eventualValues({ valid_from: '', valid_to: '' })).success
    ).toBe(true)
  })
})

describe('buildPolicyTargetSelector', () => {
  it('devuelve null para "all"', () => {
    expect(buildPolicyTargetSelector(baseValues({ target_type: 'all' }))).toBeNull()
  })

  it('devuelve { tower_ids: [...] } para "by_tower" (multiselect)', () => {
    expect(
      buildPolicyTargetSelector(
        baseValues({ target_type: 'by_tower', target_tower_ids: ['t-1', 't-2'] })
      )
    ).toEqual({ tower_ids: ['t-1', 't-2'] })
  })

  it('devuelve { section_ids: [...] } para "by_section" (multiselect)', () => {
    expect(
      buildPolicyTargetSelector(
        baseValues({ target_type: 'by_section', target_section_ids: ['s-1', 's-2'] })
      )
    ).toEqual({ section_ids: ['s-1', 's-2'] })
  })

  it('devuelve null para "with_vehicles" (sin selector, como "all")', () => {
    expect(buildPolicyTargetSelector(baseValues({ target_type: 'with_vehicles' }))).toBeNull()
  })

  it('devuelve { unit_ids } para "manual"', () => {
    expect(
      buildPolicyTargetSelector(
        baseValues({ target_type: 'manual', target_unit_ids: ['u-1', 'u-2'] })
      )
    ).toEqual({ unit_ids: ['u-1', 'u-2'] })
  })
})

describe('buildPolicyCreatePayload — alcance (target) + fechas por periodicidad', () => {
  it('recurrente: incluye target_type y vigencia, y OMITE issued_at/due_date', () => {
    const payload = buildPolicyCreatePayload(
      baseValues({
        periodicity_id: PERIODICITY.MONTHLY,
        target_type: 'all',
        issued_at: '',
        due_date: '',
      })
    )
    expect(payload.target_type).toBe('all')
    expect(payload.target_selector).toBeNull()
    expect(payload.valid_from).toBe('2026-01-01')
    expect(payload.valid_to).toBe('2026-12-31')
    expect('issued_at' in payload).toBe(false)
    expect('due_date' in payload).toBe(false)
  })

  it('recurrente: OMITE issued_at/due_date aunque ambas estén presentes (las deriva el backend de la vigencia)', () => {
    const payload = buildPolicyCreatePayload(
      baseValues({
        periodicity_id: PERIODICITY.MONTHLY,
        issued_at: '2026-02-01',
        due_date: '2026-02-15',
      })
    )
    expect('issued_at' in payload).toBe(false)
    expect('due_date' in payload).toBe(false)
  })

  it('recurrente: envía el target_selector del alcance (by_tower → { tower_ids: [...] })', () => {
    const payload = buildPolicyCreatePayload(
      baseValues({
        periodicity_id: PERIODICITY.MONTHLY,
        target_type: 'by_tower',
        target_tower_ids: ['t-9', 't-10'],
      })
    )
    expect(payload.target_type).toBe('by_tower')
    expect(payload.target_selector).toEqual({ tower_ids: ['t-9', 't-10'] })
  })

  it('eventual: incluye target_type + fechas y OMITE valid_from/valid_to', () => {
    const payload = buildPolicyCreatePayload(
      baseValues({
        amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED,
        fixed_amount: 1500,
        min_amount: null,
        ideal_amount: null,
        max_amount: null,
        periodicity_id: PERIODICITY.EVENTUAL,
        valid_from: '',
        valid_to: '',
        target_type: 'all',
        issued_at: '2026-03-01',
        due_date: '2026-03-15',
      })
    )
    expect(payload.target_type).toBe('all')
    expect(payload.issued_at).toBe('2026-03-01')
    expect(payload.due_date).toBe('2026-03-15')
    expect('valid_from' in payload).toBe(false)
    expect('valid_to' in payload).toBe(false)
  })
})

describe('buildPolicyUpdatePayload — alcance/periodicidad editables solo antes de facturar', () => {
  it('hasBilled=false: incluye periodicity_id, target_type y target_selector', () => {
    const payload = buildPolicyUpdatePayload(
      baseValues({
        target_type: 'by_tower',
        target_tower_ids: ['t-1'],
      }),
      false
    )
    expect(payload.periodicity_id).toBe(PERIODICITY.MONTHLY)
    expect(payload.target_type).toBe('by_tower')
    expect(payload.target_selector).toEqual({ tower_ids: ['t-1'] })
  })

  it('hasBilled=false + eventual: incluye issued_at/due_date', () => {
    const payload = buildPolicyUpdatePayload(
      baseValues({
        amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED,
        fixed_amount: 1500,
        min_amount: null,
        ideal_amount: null,
        max_amount: null,
        periodicity_id: PERIODICITY.EVENTUAL,
        valid_from: '',
        valid_to: '',
        target_type: 'all',
        issued_at: '2026-02-01',
        due_date: '2026-02-15',
      }),
      false
    )
    expect(payload.issued_at).toBe('2026-02-01')
    expect(payload.due_date).toBe('2026-02-15')
  })

  it('hasBilled=true: OMITE periodicity_id, target_type, target_selector, issued_at y due_date', () => {
    const payload = buildPolicyUpdatePayload(
      baseValues({
        target_type: 'by_tower',
        target_tower_ids: ['t-1'],
        issued_at: '2026-02-01',
        due_date: '2026-02-15',
      }),
      true
    )
    expect('periodicity_id' in payload).toBe(false)
    expect('target_type' in payload).toBe(false)
    expect('target_selector' in payload).toBe(false)
    expect('issued_at' in payload).toBe(false)
    expect('due_date' in payload).toBe(false)
  })

  it('hasBilled=true: sigue enviando valid_from/valid_to (vigencia siempre editable)', () => {
    const payload = buildPolicyUpdatePayload(baseValues(), true)
    expect(payload.valid_from).toBe('2026-01-01')
    expect(payload.valid_to).toBe('2026-12-31')
  })

  it('hasBilled=true: monto y nombre siguen editables', () => {
    const payload = buildPolicyUpdatePayload(baseValues({ name: 'Nuevo nombre' }), true)
    expect(payload.name).toBe('Nuevo nombre')
    expect(payload.min_amount).not.toBeUndefined()
  })
})
