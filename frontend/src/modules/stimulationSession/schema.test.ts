import { buildSessionPayload, stimulationSessionFormSchema } from '@/modules/stimulationSession/schema'
import { describe, expect, it } from 'vitest'

const base = {
  session_date: '2026-08-01',
  practitioner_id: 'practitioner-1',
  movements: [] as { body_zone_id: string; massage_movement_id: string }[],
  relaxation_zones: [] as { body_zone_id: string }[],
}

describe('stimulationSessionFormSchema — subject XOR (REQ-SS-002)', () => {
  it('accepts an individual session with child_id', () => {
    const result = stimulationSessionFormSchema.safeParse({ ...base, subject_type: 'child', child_id: 'child-1' })
    expect(result.success).toBe(true)
  })

  it('accepts a group session with stimulation_group_id', () => {
    const result = stimulationSessionFormSchema.safeParse({
      ...base,
      subject_type: 'group',
      stimulation_group_id: 'group-1',
    })
    expect(result.success).toBe(true)
  })

  it('rejects a child subject_type with no child_id selected', () => {
    const result = stimulationSessionFormSchema.safeParse({ ...base, subject_type: 'child' })
    expect(result.success).toBe(false)
  })

  it('rejects a group subject_type with no stimulation_group_id selected', () => {
    const result = stimulationSessionFormSchema.safeParse({ ...base, subject_type: 'group' })
    expect(result.success).toBe(false)
  })
})

describe('stimulationSessionFormSchema — movements/relaxation_zones arrays (REQ-SS-003)', () => {
  const withChild = { ...base, subject_type: 'child' as const, child_id: 'child-1' }

  it('accepts distinct movement pairs', () => {
    const result = stimulationSessionFormSchema.safeParse({
      ...withChild,
      movements: [
        { body_zone_id: 'zone-a', massage_movement_id: 'move-a' },
        { body_zone_id: 'zone-a', massage_movement_id: 'move-b' },
      ],
    })
    expect(result.success).toBe(true)
  })

  it('rejects a duplicate movement pair', () => {
    const result = stimulationSessionFormSchema.safeParse({
      ...withChild,
      movements: [
        { body_zone_id: 'zone-a', massage_movement_id: 'move-a' },
        { body_zone_id: 'zone-a', massage_movement_id: 'move-a' },
      ],
    })
    expect(result.success).toBe(false)
  })

  it('rejects a duplicate relaxation zone', () => {
    const result = stimulationSessionFormSchema.safeParse({
      ...withChild,
      relaxation_zones: [{ body_zone_id: 'zone-a' }, { body_zone_id: 'zone-a' }],
    })
    expect(result.success).toBe(false)
  })
})

describe('buildSessionPayload', () => {
  it('sends only child_id for an individual session and omits blank optionals', () => {
    const payload = buildSessionPayload({
      ...base,
      subject_type: 'child',
      child_id: 'child-1',
      stimulation_group_id: '',
      integration_material: '',
    })
    expect(payload.child_id).toBe('child-1')
    expect(payload).not.toHaveProperty('stimulation_group_id')
    expect(payload).not.toHaveProperty('integration_material')
  })

  it('sends only stimulation_group_id for a group session', () => {
    const payload = buildSessionPayload({
      ...base,
      subject_type: 'group',
      stimulation_group_id: 'group-1',
      child_id: '',
    })
    expect(payload.stimulation_group_id).toBe('group-1')
    expect(payload).not.toHaveProperty('child_id')
  })
})
