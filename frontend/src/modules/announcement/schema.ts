import type { IEntity, IMedia } from '@/shared/interfaces/Entity'
import {
  buildTargetSelector as buildResolverSelector,
  selectorIds,
  selectorString,
} from '@/shared/utils/targetSelector'
import { z } from 'zod'

export const ANNOUNCEMENT_STATUS = {
  DRAFT: 'ef346a2a-78c3-4d1d-9b7a-bd7245b808df',
  PUBLISHED: '03df32bb-0c89-4fc4-90e7-f15e12edf62a',
  ARCHIVED: 'fbb30570-671e-4d7b-a977-d7ae8bc50dbe',
} as const

/**
 * Tipos de alcance del aviso. Espeja EXACTAMENTE el whitelist del backend
 * (`AnnouncementService::TARGET_TYPES`); un tipo desconocido lo rechaza el server (422).
 * El aviso alcanza RESIDENTES (personas), resueltos server-side por el mismo TargetResolver
 * que usan Cargos y Chat. `by_floor`/`single_unit` quedan en el enum solo por paridad/legacy;
 * el UI no los ofrece (ver `ANNOUNCEMENT_AVAILABLE_TARGET_TYPES`).
 */
export const ANNOUNCEMENT_TARGET_TYPES = [
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
export type AnnouncementTargetType = (typeof ANNOUNCEMENT_TARGET_TYPES)[number]

/**
 * Tipos que el formulario OFRECE como tarjetas (orden incluido). Se ocultan `by_floor` y
 * `single_unit` —la selección manual cubre el caso de una unidad— pero sus ramas siguen
 * vivas en `SingleTargetSelector`. `board` (mesa directiva) sí se ofrece: un aviso puede ir
 * solo a la directiva.
 */
export const ANNOUNCEMENT_AVAILABLE_TARGET_TYPES = [
  'all',
  'by_type',
  'by_tower',
  'by_section',
  'by_feature',
  'with_vehicles',
  'manual',
  'board',
] as const

export interface IAnnouncement extends IEntity {
  title: string
  /** Texto plano — usado en búsqueda, excerpts de notificación y previews. Lo deriva el servidor. */
  content?: string | null
  /** HTML enriquecido y sanitizado por el servidor. Se usa para MOSTRAR el aviso con formato. */
  content_html?: string | null
  announcement_type_id: string
  announcement_priority_id: string
  announcement_status_id: string
  channel_push?: boolean
  channel_board?: boolean
  channel_email?: boolean
  /** Alcance del aviso: a quiénes llega (resuelto a residentes por el backend). */
  target_type?: string | null
  target_selector?: Record<string, unknown> | null
  publish_at?: string | null
  expires_at?: string | null
  author_user_id?: string | null
  type?: { id: string; name: string } | null
  priority?: { id: string; name: string } | null
  status?: { id: string; name: string } | null
  author_user?: { id: string; name?: string } | null
  reads?: Array<{ id: string }>
  /** Adjuntos (colección Spatie `attachments`) — incluidos en la lista y el board */
  media?: IMedia[]
  /** Solo en el feed del residente (GET /announcement/board) */
  is_read?: boolean
  reads_count?: number
}

export interface AnnouncementMetrics {
  total_recipients?: number
  read_count?: number
  read_pct?: number
  [key: string]: unknown
}

// ── Lectura de avisos (GET /announcement/{id}/read-summary + /readers) ───────────
// Dos endpoints custom: `read-summary` da los conteos agregados (no cambian con
// filtro/búsqueda); `readers` da la lista plana paginada de lectores (server-side
// filter/search por `status`/`search`). El universo se calcula desde el target del aviso.

/** Conteos agregados de lectura, a nivel PERSONA (una unidad puede tener varios residentes). */
export interface AnnouncementReadSummary {
  target_units_count: number
  target_people_count: number
  read_people_count: number
  unread_people_count: number
  /** Porcentaje 0-100. */
  read_percentage: number
}

/**
 * Una fila de lector: una persona en una unidad. Para avisos a la mesa directiva la
 * "unidad" es sintética (`unit.id === null`, p. ej. "Mesa directiva").
 */
export interface AnnouncementReader {
  user: { id: string; name: string }
  unit: { id: string | null; full_identifier: string }
  relationship: string | null
  read: boolean
  read_at: string | null
}

export const announcementFormSchema = z
  .object({
    title: z.string().min(1, 'El título es requerido').max(255),
    // Campo enriquecido (HTML del editor). Es el requerido: el servidor lo sanitiza y deriva
    // `content` (texto plano) a partir de él.
    content_html: z.string().min(1, 'El contenido es requerido'),
    // Texto plano — lo deriva el servidor desde `content_html`; el form ya no lo captura.
    content: z.string().optional(),
    announcement_type_id: z.string().min(1, 'El tipo es requerido'),
    announcement_priority_id: z.string().min(1, 'La prioridad es requerida'),
    channel_push: z.boolean(),
    channel_board: z.boolean(),
    channel_email: z.boolean(),
    expires_at: z.string().optional(),
    // Alcance (§12.3.3) — ¿quiénes reciben el aviso? tipo/torre/sección/característica son
    // MULTISELECT; `target_floor`/`target_unit_id` quedan vivos solo para datos legacy (el UI
    // no los ofrece). `all`/`with_vehicles`/`board` no llevan campo propio.
    target_type: z.enum(ANNOUNCEMENT_TARGET_TYPES),
    target_unit_type_ids: z.array(z.string()).optional(),
    target_tower_ids: z.array(z.string()).optional(),
    target_section_ids: z.array(z.string()).optional(),
    target_feature_ids: z.array(z.string()).optional(),
    target_floor: z.string().optional(),
    target_unit_id: z.string().optional(),
    target_unit_ids: z.array(z.string()).optional(),
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
      case 'by_feature':
        need(
          !values.target_feature_ids?.length,
          'target_feature_ids',
          'Selecciona al menos una característica'
        )
        break
      case 'manual':
        need(!values.target_unit_ids?.length, 'target_unit_ids', 'Selecciona al menos una unidad')
        break
    }
  })

export type AnnouncementFormValues = z.infer<typeof announcementFormSchema>

/** Subconjunto de campos de alcance que consume `buildAnnouncementTargetSelector`. */
export type AnnouncementTargetInput = Pick<
  AnnouncementFormValues,
  | 'target_type'
  | 'target_unit_type_ids'
  | 'target_tower_ids'
  | 'target_section_ids'
  | 'target_feature_ids'
  | 'target_floor'
  | 'target_unit_id'
  | 'target_unit_ids'
>

/**
 * Construye el `target_selector` jsonb que consume el backend (mismas keys del TargetResolver
 * que Cargos/Chat). `all`/`with_vehicles`/`board` no llevan selector → `null`.
 */
export function buildAnnouncementTargetSelector(
  values: AnnouncementTargetInput
): Record<string, unknown> | null {
  return buildResolverSelector(values.target_type, {
    unitType: values.target_unit_type_ids,
    tower: values.target_tower_ids,
    section: values.target_section_ids,
    floor: values.target_floor,
    feature: values.target_feature_ids,
    unitId: values.target_unit_id ?? '',
    unitIds: values.target_unit_ids,
  })
}

/**
 * Escapa texto plano y lo envuelve en <p> para sembrar el editor al editar un aviso
 * legacy (creado antes del rich text: tiene `content` plano pero `content_html` null).
 * Sin esto el editor abriria vacio y bloquearia el guardado.
 */
function plainTextToHtml(text: string): string {
  const escaped = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
  return `<p>${escaped}</p>`
}

/**
 * Valores por defecto del formulario a partir de un aviso existente (edición, lectura inversa
 * del `target_selector` guardado) o en blanco (alta). Un `target_type` desconocido cae a `all`.
 */
export function buildAnnouncementFormDefaults(
  announcement?: IAnnouncement | null
): AnnouncementFormValues {
  const targetType = (ANNOUNCEMENT_TARGET_TYPES as readonly string[]).includes(
    announcement?.target_type ?? ''
  )
    ? (announcement?.target_type as AnnouncementTargetType)
    : 'all'
  const sel = announcement?.target_selector

  return {
    title: announcement?.title ?? '',
    // En edición el editor arranca con el HTML guardado; si es un aviso legacy (solo texto
    // plano) lo derivamos del `content` para no abrir vacío; en alta, vacío.
    content_html:
      announcement?.content_html ||
      (announcement?.content ? plainTextToHtml(announcement.content) : ''),
    content: announcement?.content ?? '',
    announcement_type_id: announcement?.announcement_type_id ?? '',
    announcement_priority_id: announcement?.announcement_priority_id ?? '',
    channel_push: announcement?.channel_push ?? true,
    channel_board: announcement?.channel_board ?? true,
    channel_email: announcement?.channel_email ?? false,
    // `expires_at` llega del backend como ISO; <input type="date"> requiere "YYYY-MM-DD".
    expires_at: announcement?.expires_at ? announcement.expires_at.slice(0, 10) : '',
    target_type: targetType,
    target_unit_type_ids: selectorIds(sel, 'unit_type_ids'),
    target_tower_ids: selectorIds(sel, 'tower_ids'),
    target_section_ids: selectorIds(sel, 'section_ids'),
    target_feature_ids: selectorIds(sel, 'unit_feature_ids'),
    // `by_floor` está oculto en el UI y no hay datos legacy de piso → no se hidrata (no es un bug).
    target_floor: '',
    target_unit_id: selectorString(sel, 'unit_id'),
    target_unit_ids: selectorIds(sel, 'unit_ids'),
  }
}
