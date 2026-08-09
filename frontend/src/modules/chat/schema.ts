import type { IEntity } from '@/shared/interfaces/Entity'
import { buildTargetSelector as buildResolverSelector } from '@/shared/utils/targetSelector'
import { z } from 'zod'

/** Tipos de canal con lógica propia (catálogo central chat_channel_type). */
export const CHAT_CHANNEL_TYPE = {
  DIRECT: 'dd2fe0f0-877f-46c8-bcdb-1fce2f2d4c83',
} as const

export interface IChatChannel extends IEntity {
  name: string
  chat_channel_type_id?: string | null
  icon?: string | null
  target_type?: string | null
  target_selector?: Record<string, unknown> | null
  /** Write target: who may post (mirrors read target vocabulary). */
  write_target_type?: string | null
  write_target_selector?: Record<string, unknown> | null
  is_moderated?: boolean
  is_system?: boolean
  is_archived?: boolean
  notify_on_post?: boolean
  /** Computed per-user flags (stamped by the backend channel resource). */
  can_read?: boolean
  can_write?: boolean
  channel_type?: { id: string; name: string } | null
  members?: Array<{ id: string; user_id: string; user?: { id: string; name?: string } | null }>
}

// ── Channel administration (Fase 2) ─────────────────────────────────────────

/**
 * Target types exposed in the admin form. Mirrors backend ChatChannel::ADMIN_TARGET_TYPES.
 * 'by_share' and 'direct' are backend-only and not offered here.
 * 'manual' targets UNITS (unit_ids), never people.
 */
export const CHANNEL_TARGET_TYPES = [
  'all',
  'by_type',
  'by_tower',
  'by_section',
  'by_floor',
  'by_feature',
  'with_vehicles',
  'single_unit',
  'manual',
  'board',
] as const
export type ChannelTargetType = (typeof CHANNEL_TARGET_TYPES)[number]

export const CHANNEL_TARGET_TYPE_LABELS: Record<ChannelTargetType, string> = {
  all: 'Todo el condominio',
  by_type: 'Por tipo de unidad',
  by_tower: 'Por torre',
  by_section: 'Por sección',
  by_floor: 'Por piso',
  by_feature: 'Por característica',
  with_vehicles: 'Con vehículos',
  single_unit: 'Una sola unidad',
  manual: 'Selección manual de unidades',
  board: 'Solo la mesa directiva',
}

/**
 * Tipos de alcance que el UID del canal OFRECE como tarjetas (orden incluido). Se ocultan
 * `by_floor` y `single_unit` —la selección manual cubre el caso de una unidad— pero sus
 * ramas siguen vivas en `SingleTargetSelector` para hidratar canales legacy que ya los usan.
 * `board` (mesa directiva) es exclusivo de Chat y se inyecta acá (cobranza no lo muestra).
 */
export const CHANNEL_AVAILABLE_TARGET_TYPES = [
  'all',
  'by_type',
  'by_tower',
  'by_section',
  'by_feature',
  'with_vehicles',
  'manual',
  'board',
] as const

export const channelFormSchema = z
  .object({
    name: z.string().min(1, 'El nombre es requerido').max(255),
    chat_channel_type_id: z.string().min(1, 'El tipo es requerido'),
    icon: z.string().max(50).optional(),
    description: z.string().max(1000).optional(),
    // READ target — who sees / receives the channel.
    // `by_type` es MULTISELECT (varios tipos); `target_floor`/`single_unit` quedan vivos
    // solo para datos legacy (ya no se ofrecen en el UI). `with_vehicles` no lleva campo
    // propio: sin selector, igual que `all`.
    target_type: z.enum(CHANNEL_TARGET_TYPES),
    target_unit_type_ids: z.array(z.string()).optional(),
    target_tower_ids: z.array(z.string()).optional(),
    target_section_ids: z.array(z.string()).optional(),
    target_floors: z.array(z.string()).optional(),
    target_unit_feature_ids: z.array(z.string()).optional(),
    target_unit_id: z.string().optional(),
    target_unit_ids: z.array(z.string()).optional(),
    // WRITE target — who may post
    write_target_type: z.enum(CHANNEL_TARGET_TYPES),
    write_target_unit_type_ids: z.array(z.string()).optional(),
    write_target_tower_ids: z.array(z.string()).optional(),
    write_target_section_ids: z.array(z.string()).optional(),
    write_target_floors: z.array(z.string()).optional(),
    write_target_unit_feature_ids: z.array(z.string()).optional(),
    write_target_unit_id: z.string().optional(),
    write_target_unit_ids: z.array(z.string()).optional(),
    notify_on_post: z.boolean(),
  })
  .superRefine((values, ctx) => {
    const need = (cond: boolean, path: string, message: string) => {
      if (cond) ctx.addIssue({ code: 'custom', path: [path], message })
    }
    switch (values.target_type) {
      case 'by_type':
        need(
          !values.target_unit_type_ids?.length,
          'target_unit_type_ids',
          'Selecciona al menos un tipo de unidad'
        )
        break
      case 'by_tower':
        need(!values.target_tower_ids?.length, 'target_tower_ids', 'Selecciona al menos una torre')
        break
      case 'by_section':
        need(
          !values.target_section_ids?.length,
          'target_section_ids',
          'Selecciona al menos una sección'
        )
        break
      case 'by_floor':
        need(!values.target_tower_ids?.length, 'target_tower_ids', 'Selecciona al menos una torre')
        need(!values.target_floors?.length, 'target_floors', 'Ingresa al menos un piso')
        break
      case 'by_feature':
        need(
          !values.target_unit_feature_ids?.length,
          'target_unit_feature_ids',
          'Selecciona al menos una característica'
        )
        break
      case 'single_unit':
        need(!values.target_unit_id, 'target_unit_id', 'Selecciona la unidad')
        break
      case 'manual':
        need(!values.target_unit_ids?.length, 'target_unit_ids', 'Selecciona al menos una unidad')
        break
    }
    // WRITE target validation (mirrors read target)
    switch (values.write_target_type) {
      case 'by_type':
        need(
          !values.write_target_unit_type_ids?.length,
          'write_target_unit_type_ids',
          'Selecciona al menos un tipo de unidad'
        )
        break
      case 'by_tower':
        need(
          !values.write_target_tower_ids?.length,
          'write_target_tower_ids',
          'Selecciona al menos una torre'
        )
        break
      case 'by_section':
        need(
          !values.write_target_section_ids?.length,
          'write_target_section_ids',
          'Selecciona al menos una sección'
        )
        break
      case 'by_floor':
        need(
          !values.write_target_tower_ids?.length,
          'write_target_tower_ids',
          'Selecciona al menos una torre'
        )
        need(!values.write_target_floors?.length, 'write_target_floors', 'Ingresa al menos un piso')
        break
      case 'by_feature':
        need(
          !values.write_target_unit_feature_ids?.length,
          'write_target_unit_feature_ids',
          'Selecciona al menos una característica'
        )
        break
      case 'single_unit':
        need(!values.write_target_unit_id, 'write_target_unit_id', 'Selecciona la unidad')
        break
      case 'manual':
        need(
          !values.write_target_unit_ids?.length,
          'write_target_unit_ids',
          'Selecciona al menos una unidad'
        )
        break
    }
  })

export type ChannelFormValues = z.infer<typeof channelFormSchema>

/**
 * Build the READ target_selector jsonb the backend expects. Delegates the key mapping
 * to the shared `buildTargetSelector` (single source of truth, same keys as the charges
 * TargetResolver). Chat uses MULTI fields throughout: by_type sends target_unit_type_ids
 * (string[]) directly; the shared builder copies it via toIdArray. `by_floor` filters by
 * tower AND floor → `floorIncludesTowers: true`.
 */
export function buildChannelTargetSelector(
  values: ChannelFormValues
): Record<string, unknown> | null {
  return buildResolverSelector(
    values.target_type,
    {
      unitType: values.target_unit_type_ids,
      tower: values.target_tower_ids,
      section: values.target_section_ids,
      floor: values.target_floors,
      feature: values.target_unit_feature_ids,
      unitId: values.target_unit_id ?? '',
      unitIds: values.target_unit_ids,
    },
    { floorIncludesTowers: true }
  )
}

/**
 * Build the WRITE target_selector jsonb. Same shapes as `buildChannelTargetSelector`
 * but reads the `write_*` form fields.
 */
export function buildChannelWriteTargetSelector(
  values: ChannelFormValues
): Record<string, unknown> | null {
  return buildResolverSelector(
    values.write_target_type,
    {
      unitType: values.write_target_unit_type_ids,
      tower: values.write_target_tower_ids,
      section: values.write_target_section_ids,
      floor: values.write_target_floors,
      feature: values.write_target_unit_feature_ids,
      unitId: values.write_target_unit_id ?? '',
      unitIds: values.write_target_unit_ids,
    },
    { floorIncludesTowers: true }
  )
}

/**
 * Read an id-list out of a channel's target_selector. Falls back to wrapping a legacy
 * scalar string into a 1-element array (same shape as charges' `buildPolicyDefaults`), so
 * a selector stored before the SINGLE→MULTI migration still hydrates the multiselect.
 */
function selectorIds(selector: Record<string, unknown> | null | undefined, key: string): string[] {
  if (!selector) return []
  const value = selector[key]
  if (Array.isArray(value)) return value.filter((item): item is string => typeof item === 'string')
  return typeof value === 'string' && value ? [value] : []
}

/** Read a scalar string out of a channel's target_selector. */
function selectorString(
  selector: Record<string, unknown> | null | undefined,
  key: string
): string | undefined {
  if (!selector) return undefined
  const value = selector[key]
  return typeof value === 'string' ? value : undefined
}

/** Build the form defaults from an existing channel (edit) or blanks (create). */
export function buildChannelFormDefaults(channel?: IChatChannel | null): ChannelFormValues {
  const targetType = (CHANNEL_TARGET_TYPES as readonly string[]).includes(
    channel?.target_type ?? ''
  )
    ? (channel?.target_type as ChannelTargetType)
    : 'all'

  const writeTargetType = (CHANNEL_TARGET_TYPES as readonly string[]).includes(
    channel?.write_target_type ?? ''
  )
    ? (channel?.write_target_type as ChannelTargetType)
    : 'all'

  const sel = channel?.target_selector
  const wSel = channel?.write_target_selector

  return {
    name: channel?.name ?? '',
    chat_channel_type_id: channel?.chat_channel_type_id ?? '',
    icon: channel?.icon ?? '',
    description: channel?.description ?? '',
    target_type: targetType,
    target_unit_type_ids: selectorIds(sel, 'unit_type_ids'),
    target_tower_ids: selectorIds(sel, 'tower_ids'),
    target_section_ids: selectorIds(sel, 'section_ids'),
    target_floors: selectorIds(sel, 'floors'),
    target_unit_feature_ids: selectorIds(sel, 'unit_feature_ids'),
    target_unit_id: selectorString(sel, 'unit_id'),
    target_unit_ids: selectorIds(sel, 'unit_ids'),
    write_target_type: writeTargetType,
    write_target_unit_type_ids: selectorIds(wSel, 'unit_type_ids'),
    write_target_tower_ids: selectorIds(wSel, 'tower_ids'),
    write_target_section_ids: selectorIds(wSel, 'section_ids'),
    write_target_floors: selectorIds(wSel, 'floors'),
    write_target_unit_feature_ids: selectorIds(wSel, 'unit_feature_ids'),
    write_target_unit_id: selectorString(wSel, 'unit_id'),
    write_target_unit_ids: selectorIds(wSel, 'unit_ids'),
    notify_on_post: channel?.notify_on_post ?? true,
  }
}

export interface IChatMessageReaction {
  id: string
  emoji: string
  user_id: string
}

export interface IChatMessage extends IEntity {
  chat_channel_id: string
  user_id?: string | null
  body?: string | null
  /** Sanitized rich-text HTML sent by the backend (nullable for plain-text messages). */
  body_html?: string | null
  is_hidden?: boolean
  edited_at?: string | null
  reply_to_id?: string | null
  user?: { id: string; name?: string } | null
  reply_to?: IChatMessage | null
  reactions?: IChatMessageReaction[]
  // media is inherited from IEntity (attachments collection via Spatie Media Library)
}

export interface IChatMessageReport extends IEntity {
  chat_message_id: string
  reporter_user_id?: string | null
  reason?: string | null
  status?: string | null
  message?: IChatMessage | null
  reporter_user?: { id: string; name?: string } | null
}
