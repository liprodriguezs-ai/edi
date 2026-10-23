import { stimulationGroupFormSchema } from '@/modules/stimulationGroup/schema'
import { describe, expect, it } from 'vitest'

const base = {
  name: 'Grupo matutino',
  stimulation_group_type_id: 'type-1',
  health_center_id: 'center-1',
  promoter_user_id: 'user-1',
  start_date: '2026-01-01',
  is_active: true,
}

describe('stimulationGroupFormSchema — date coherence (REQ-SG-001)', () => {
  it('accepts a payload with no end_date', () => {
    expect(stimulationGroupFormSchema.safeParse(base).success).toBe(true)
  })

  it('accepts end_date equal to start_date', () => {
    expect(stimulationGroupFormSchema.safeParse({ ...base, end_date: '2026-01-01' }).success).toBe(true)
  })

  it('accepts end_date after start_date', () => {
    expect(stimulationGroupFormSchema.safeParse({ ...base, end_date: '2026-06-01' }).success).toBe(true)
  })

  it('rejects end_date before start_date', () => {
    const result = stimulationGroupFormSchema.safeParse({ ...base, end_date: '2025-12-01' })
    expect(result.success).toBe(false)
  })

  it('rejects a missing name', () => {
    const { name: _omitted, ...payload } = base
    expect(stimulationGroupFormSchema.safeParse(payload).success).toBe(false)
  })
})
