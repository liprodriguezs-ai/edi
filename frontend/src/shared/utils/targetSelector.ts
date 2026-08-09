/**
 * Builder PURO del `target_selector` jsonb que consume el backend `TargetResolver`.
 *
 * Fuente ÚNICA del mapeo `target_type → keys del resolver`, compartida por Cargos
 * (lote + política, alcance de campos SINGLE) y Chat (canal, alcance MULTI, read + write).
 *
 * Keys EXACTAS que lee el TargetResolver:
 *   `unit_type_ids[]` / `tower_ids[]` / `section_ids[]` / `floors[]` / `unit_feature_ids[]` /
 *   `unit_ids[]` / `unit_id` (escalar).
 *
 * Acá vivía el bug histórico: las keys SINGULARES (tower_id / floor / feature /
 * unit_type_id) no matcheaban el resolver, así que los lotes por torre/tipo/piso/
 * característica no generaban cargos (y `by_feature`, con lista vacía, caía en
 * `allActive()` cobrando a TODAS las unidades). Centralizado acá para que el mapeo no
 * vuelva a divergir entre los tres puntos que lo replicaban.
 */

/** Un id suelto, un arreglo de ids, o nada. */
type IdOrIds = string | string[] | null | undefined

/**
 * Campos de alcance NORMALIZADOS, agnósticos al nombre que cada formulario les dé.
 * El builder acepta tanto un id suelto (SINGLE — ej. Cargos: `target_tower_id`) como un
 * arreglo (MULTI — ej. Chat: `target_tower_ids`):
 *  - single → se envuelve en `[id]`
 *  - multi  → se pasa tal cual
 */
export interface TargetSelectorFields {
  /** `by_type` → `unit_type_ids[]`. */
  unitType?: IdOrIds
  /** `by_tower` (y `by_floor` en Chat) → `tower_ids[]`. */
  tower?: IdOrIds
  /** `by_section` → `section_ids[]`. */
  section?: IdOrIds
  /** `by_floor` → `floors[]`. */
  floor?: IdOrIds
  /** `by_feature` → `unit_feature_ids[]`. */
  feature?: IdOrIds
  /** `single_unit` → `unit_id` (escalar, no array). */
  unitId?: string
  /** `manual` → `unit_ids[]`. */
  unitIds?: string[]
}

export interface BuildTargetSelectorOptions {
  /**
   * Cuando es `true`, `by_floor` ADEMÁS emite `tower_ids` (Chat: pisos dentro de torres
   * específicas, exige ambos). Cargos lo deja en `false` → el piso aplica a TODAS las
   * torres (un solo input de piso, sin selección de torre). Default: `false`.
   */
  floorIncludesTowers?: boolean
}

/** id suelto → `[id]`; arreglo → copia; vacío/nulo → `[]`. */
function toIdArray(input: IdOrIds): string[] {
  if (Array.isArray(input)) {
    return [...input]
  }
  return input ? [input] : []
}

/**
 * Mapea `target_type` + los campos de alcance a la forma del `target_selector` que
 * espera el backend. Tipos sin selector (`all`, `by_share`, `board` y cualquier otro
 * desconocido) devuelven `null`.
 */
export function buildTargetSelector(
  targetType: string,
  fields: TargetSelectorFields,
  options: BuildTargetSelectorOptions = {}
): Record<string, unknown> | null {
  switch (targetType) {
    case 'by_type':
      return { unit_type_ids: toIdArray(fields.unitType) }
    case 'by_tower':
      return { tower_ids: toIdArray(fields.tower) }
    case 'by_section':
      return { section_ids: toIdArray(fields.section) }
    case 'by_floor':
      if (options.floorIncludesTowers) {
        return { tower_ids: toIdArray(fields.tower), floors: toIdArray(fields.floor) }
      }
      return { floors: toIdArray(fields.floor) }
    case 'by_feature':
      return { unit_feature_ids: toIdArray(fields.feature) }
    case 'single_unit':
      return { unit_id: fields.unitId }
    case 'manual':
      return { unit_ids: fields.unitIds ?? [] }
    case 'with_vehicles':
      // Sin selector: unidades con ≥1 vehículo registrado, resuelto server-side (como 'all').
      return null
    default:
      return null
  }
}

/**
 * Lectura INVERSA del `target_selector`: extrae una lista de ids de una key. Envuelve un
 * escalar legacy (string suelto, previo a la migración SINGLE→MULTI) en `[valor]`. Espejo del
 * builder de arriba — vive acá para que Cargos/Chat/Avisos hidraten sus forms con la MISMA
 * lógica que produjo el selector.
 */
export function selectorIds(
  selector: Record<string, unknown> | null | undefined,
  key: string
): string[] {
  if (!selector) return []
  const value = selector[key]
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string')
  }
  return typeof value === 'string' && value ? [value] : []
}

/** Lectura INVERSA de un escalar string del `target_selector` (p. ej. `unit_id`). */
export function selectorString(
  selector: Record<string, unknown> | null | undefined,
  key: string
): string | undefined {
  if (!selector) return undefined
  const value = selector[key]
  return typeof value === 'string' ? value : undefined
}

/** `true` si el valor es un arreglo con al menos un elemento. */
function arrHasItems(value: unknown): boolean {
  return Array.isArray(value) && value.length > 0
}

/**
 * ¿El alcance está lo bastante definido como para consultar su conteo? Los tipos sin
 * sub-selección (`all`/`with_vehicles`/`board`) siempre lo están; los que filtran por una
 * lista/valor, sólo cuando ya hay algo elegido — así la vista previa NO dispara un
 * `POST /preview` garantizado-0 apenas se cambia el radio y antes de elegir nada.
 */
export function isSelectorReady(
  targetType: string,
  selector: Record<string, unknown> | null
): boolean {
  if (targetType === 'all' || targetType === 'with_vehicles' || targetType === 'board') {
    return true
  }
  if (!selector) return false
  switch (targetType) {
    case 'by_type':
      return arrHasItems(selector.unit_type_ids)
    case 'by_tower':
      return arrHasItems(selector.tower_ids)
    case 'by_section':
      return arrHasItems(selector.section_ids)
    case 'by_feature':
      return arrHasItems(selector.unit_feature_ids)
    case 'manual':
      return arrHasItems(selector.unit_ids)
    case 'by_floor':
      return arrHasItems(selector.floors)
    case 'single_unit':
      return typeof selector.unit_id === 'string' && selector.unit_id.length > 0
    default:
      return false
  }
}
