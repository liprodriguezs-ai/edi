import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export interface IStimulationSessionMovement {
  body_zone_id: string
  massage_movement_id: string
}

export interface IStimulationRelaxationZone {
  body_zone_id: string
}

const movementSchema = z.object({
  body_zone_id: z.string().min(1),
  massage_movement_id: z.string().min(1),
})

const relaxationZoneSchema = z.object({
  body_zone_id: z.string().min(1),
})

/**
 * REQ-SS-002 (subject XOR) and REQ-SS-003 (no duplicate movement/relaxation
 * pairs) are re-validated client-side for immediate feedback; the backend
 * enforces both as the source of truth (StimulationSession{Create,Update}Request).
 */
export const stimulationSessionFormSchema = z
  .object({
    subject_type: z.enum(['child', 'group']),
    child_id: z.string().optional(),
    stimulation_group_id: z.string().optional(),
    visit_id: z.string().optional(),
    session_date: z.string().min(1, 'La fecha de la sesión es requerida'),
    practitioner_id: z.string().min(1, 'El profesional es requerido'),
    integration_dynamic_id: z.string().optional(),
    integration_material: z.string().optional(),
    integration_song: z.string().optional(),
    preparation_song: z.string().optional(),
    core_stimulation_area_id: z.string().optional(),
    core_dynamic_id: z.string().optional(),
    core_activity: z.string().optional(),
    core_material: z.string().optional(),
    core_song: z.string().optional(),
    relaxation_notes: z.string().optional(),
    observations: z.string().optional(),
    movements: z.array(movementSchema),
    relaxation_zones: z.array(relaxationZoneSchema),
  })
  .refine((v) => (v.subject_type === 'child' ? Boolean(v.child_id) : Boolean(v.stimulation_group_id)), {
    message: 'Selecciona un niño o un grupo',
    path: ['child_id'],
  })
  .refine(
    (v) => {
      const seen = new Set<string>()
      for (const m of v.movements) {
        const key = `${m.body_zone_id}|${m.massage_movement_id}`
        if (seen.has(key)) return false
        seen.add(key)
      }
      return true
    },
    { message: 'No puedes repetir la misma combinación de zona y movimiento', path: ['movements'] }
  )
  .refine(
    (v) => {
      const seen = new Set<string>()
      for (const zone of v.relaxation_zones) {
        if (seen.has(zone.body_zone_id)) return false
        seen.add(zone.body_zone_id)
      }
      return true
    },
    { message: 'No puedes repetir la misma zona de relajación', path: ['relaxation_zones'] }
  )

export type StimulationSessionFormValues = z.infer<typeof stimulationSessionFormSchema>

/**
 * Translates the form shape (subject_type discriminator, empty-string
 * optionals) into the API payload the backend expects (exactly one subject
 * id present, blank optionals omitted so they don't overwrite existing
 * values with empty strings on update).
 */
export function buildSessionPayload(values: StimulationSessionFormValues): Record<string, unknown> {
  const optionalKeys = [
    'visit_id',
    'integration_dynamic_id',
    'integration_material',
    'integration_song',
    'preparation_song',
    'core_stimulation_area_id',
    'core_dynamic_id',
    'core_activity',
    'core_material',
    'core_song',
    'relaxation_notes',
    'observations',
  ] as const

  const payload: Record<string, unknown> = {
    session_date: values.session_date,
    practitioner_id: values.practitioner_id,
    movements: values.movements,
    relaxation_zones: values.relaxation_zones,
  }

  if (values.subject_type === 'child') {
    payload.child_id = values.child_id
  } else {
    payload.stimulation_group_id = values.stimulation_group_id
  }

  for (const key of optionalKeys) {
    const value = values[key]
    if (value !== undefined && value !== '') {
      payload[key] = value
    }
  }

  return payload
}

export interface IStimulationSession extends IEntity {
  child_id?: string | null
  stimulation_group_id?: string | null
  visit_id?: string | null
  session_date: string
  practitioner_id: string
  integration_dynamic_id?: string | null
  integration_material?: string | null
  integration_song?: string | null
  preparation_song?: string | null
  core_stimulation_area_id?: string | null
  core_dynamic_id?: string | null
  core_activity?: string | null
  core_material?: string | null
  core_song?: string | null
  relaxation_notes?: string | null
  observations?: string | null
  movements?: IStimulationSessionMovement[]
  relaxation_zones?: IStimulationRelaxationZone[]
  child?: {
    id: string
    first_name: string
    last_name_paternal: string
    last_name_maternal?: string | null
  } | null
  stimulation_group?: { id: string; name: string } | null
  practitioner?: { id: string; user?: { id: string; name?: string } | null } | null
}
