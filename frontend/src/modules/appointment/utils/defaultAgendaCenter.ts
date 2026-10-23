/** Centro que la agenda muestra al abrir cuando el usuario aún no eligió uno. */
export interface AgendaPractitionerRef {
  user_id?: string | null
  health_center_id?: string | null
}

/**
 * 1. Si el usuario es profesional, su propio centro.
 * 2. Si todos los profesionales trabajan en el mismo centro, ese.
 * 3. Si no, `null` (todos los centros).
 */
export function defaultAgendaCenter(practitioners: AgendaPractitionerRef[], userId?: string | null): string | null {
  const own = userId ? practitioners.find((practitioner) => practitioner.user_id === userId) : undefined
  if (own?.health_center_id) return own.health_center_id

  const centers = new Set(practitioners.map((practitioner) => practitioner.health_center_id).filter(Boolean))
  if (centers.size === 1) return [...centers][0] ?? null

  return null
}

const STORAGE_KEY = 'edi.agenda.healthCenterId'

/** Última elección del usuario en este navegador (conveniencia; puede no existir). */
export function readStoredAgendaCenter(): string | null {
  try {
    return window.localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function storeAgendaCenter(value: string): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, value)
  } catch {
    // Storage blocked (private window): the choice just isn't remembered.
  }
}
