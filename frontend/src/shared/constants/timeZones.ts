/** Zona horaria por defecto de un tenant nuevo (coincide con el default del backend). */
export const DEFAULT_TENANT_TIME_ZONE = 'America/Mexico_City'

export interface ITimeZoneOption {
  /** Identificador IANA que se guarda en `tenant.timezone`. */
  value: string
  /** Etiqueta en español para la UI. */
  label: string
}

/**
 * Zonas horarias oficiales de México (IANA). ÚNICA fuente de opciones para los
 * selects de zona horaria del tenant.
 */
export const MEXICO_TIME_ZONES: readonly ITimeZoneOption[] = [
  { value: 'America/Mexico_City', label: 'Centro (Ciudad de México)' },
  { value: 'America/Monterrey', label: 'Centro (Monterrey)' },
  { value: 'America/Merida', label: 'Centro (Mérida)' },
  { value: 'America/Chihuahua', label: 'Centro (Chihuahua)' },
  { value: 'America/Bahia_Banderas', label: 'Centro (Bahía de Banderas)' },
  { value: 'America/Matamoros', label: 'Centro, frontera norte (Matamoros)' },
  { value: 'America/Ojinaga', label: 'Centro, frontera norte (Ojinaga)' },
  { value: 'America/Cancun', label: 'Sureste (Cancún)' },
  { value: 'America/Mazatlan', label: 'Pacífico (Mazatlán)' },
  { value: 'America/Hermosillo', label: 'Sonora (Hermosillo)' },
  { value: 'America/Ciudad_Juarez', label: 'Pacífico, frontera norte (Ciudad Juárez)' },
  { value: 'America/Tijuana', label: 'Noroeste (Tijuana)' },
]
