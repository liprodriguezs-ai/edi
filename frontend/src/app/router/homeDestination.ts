import { ERoleUserSlug, roleRoutes } from '@/shared/interfaces/Entity'

/** Ruta de inicio del personal clínico: el día de hoy en la agenda. */
export const CLINICAL_HOME = '/agenda'

/**
 * Where "/" lands after login. Whoever can read the agenda starts on today's
 * agenda; the platform super-admin keeps its own page, and everyone else keeps
 * their role route.
 */
export function homeDestination(roles: string[], canReadAgenda: boolean): string {
  if (roles.includes(ERoleUserSlug.SUPER_ADMIN)) return roleRoutes[ERoleUserSlug.SUPER_ADMIN]
  if (canReadAgenda) return CLINICAL_HOME

  const role = roles.find((value): value is ERoleUserSlug =>
    Object.values(ERoleUserSlug).includes(value as ERoleUserSlug)
  )
  return role ? (roleRoutes[role] ?? '/dashboard') : '/dashboard'
}
