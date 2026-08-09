import { sessionStore } from '@/shared/auth/sessionStore'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useMemo } from 'react'
import { useStore } from 'zustand'

const EMPTY_ROLES: ERoleUserSlug[] = []

export type UseRolesHelpers = {
  roles: ERoleUserSlug[]
  hasRole: (_role: ERoleUserSlug) => boolean
  hasAnyRole: (_allowedRoles: ERoleUserSlug[]) => boolean
  hasAllRoles: (_requiredRoles: ERoleUserSlug[]) => boolean
  findFirstRole: (_rolesToMatch: ERoleUserSlug[]) => ERoleUserSlug | null
}

/**
 * Hook para verificar roles (equivalente a AclrPipe)
 * Permite usarse de dos maneras:
 * - `const match = useRoles(['admin'], true);`
 * - `const { hasAnyRole } = useRoles();`
 */
export function useRoles(): UseRolesHelpers
export function useRoles(
  _key: ERoleUserSlug | ERoleUserSlug[],
  _unique?: boolean
): boolean | ERoleUserSlug | null
export function useRoles(
  key?: ERoleUserSlug | ERoleUserSlug[],
  unique = false
): UseRolesHelpers | boolean | ERoleUserSlug | null {
  const storeRoles = useStore(sessionStore, (state) => state.profile?.roles ?? EMPTY_ROLES)

  const helpers = useMemo<UseRolesHelpers>(() => {
    const roles = Array.isArray(storeRoles) ? storeRoles : []

    const hasRole = (role: ERoleUserSlug) => roles.includes(role)

    const hasAnyRole = (allowedRoles: ERoleUserSlug[]) => {
      if (!roles.length || !Array.isArray(allowedRoles) || !allowedRoles.length) {
        return false
      }
      return allowedRoles.some((role) => roles.includes(role))
    }

    const hasAllRoles = (requiredRoles: ERoleUserSlug[]) => {
      if (!roles.length || !Array.isArray(requiredRoles) || !requiredRoles.length) {
        return false
      }
      return requiredRoles.every((role) => roles.includes(role))
    }

    const findFirstRole = (rolesToMatch: ERoleUserSlug[]) => {
      if (!roles.length || !Array.isArray(rolesToMatch) || !rolesToMatch.length) {
        return null
      }
      return rolesToMatch.find((role) => roles.includes(role)) ?? null
    }

    return {
      roles,
      hasRole,
      hasAnyRole,
      hasAllRoles,
      findFirstRole,
    }
  }, [storeRoles])

  if (typeof key === 'undefined') {
    return helpers
  }

  if (!helpers.roles.length) {
    return null
  }

  if (typeof key === 'string') {
    return helpers.hasRole(key)
  }

  if (Array.isArray(key)) {
    if (unique) {
      return helpers.findFirstRole(key)
    }
    return helpers.hasAnyRole(key)
  }

  return false
}
