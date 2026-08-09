import type { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useRoles } from '@/shared/hooks/useRoles'
import { useMemo } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'

const ACCESS_DENIED_PATH = '/acceso-denegado'

/** Misma convención que el sidebar: read.{entity} o report.{entity} */
function entityToPermissions(entity: string): string[] {
  return [`read.${entity}`, `report.${entity}`]
}

export interface PermissionRouteProps {
  /**
   * Permiso(s) requeridos (ej. 'read.company' o ['read.collaborator', 'report.collaborator']).
   * Con array, basta tener al menos uno (o todos si requireAll).
   */
  permission?: string | string[]
  /**
   * Atajo por entidad: equivale a permission={['read.{entity}', 'report.{entity}']}.
   * Misma lógica que el sidebar (permissionEntity).
   */
  permissionEntity?: string
  /** Rol(es) requeridos. Si es array, basta con tener al menos uno (o todos si requireAll). */
  role?: ERoleUserSlug | ERoleUserSlug[]
  /** Si true, requiere TODOS los permisos/roles; si false, al menos uno. */
  requireAll?: boolean
}

function isAllowed(
  hasPermission: boolean,
  hasRole: boolean,
  permission?: string | string[],
  role?: ERoleUserSlug | ERoleUserSlug[],
  requireAll?: boolean
): boolean {
  if (!permission && !role) return true
  if (permission && !role) return hasPermission
  if (role && !permission) return hasRole
  return requireAll ? hasPermission && hasRole : hasPermission || hasRole
}

/**
 * Guard de ruta: si el usuario no tiene el permiso o rol indicado, redirige a la página
 * "Zona protegida" (/acceso-denegado) en lugar de renderizar la ruta.
 */
export function PermissionRoute({
  permission,
  permissionEntity,
  role,
  requireAll = false,
}: PermissionRouteProps) {
  const location = useLocation()

  const effectivePermission = useMemo(() => {
    if (permissionEntity) return entityToPermissions(permissionEntity)
    return permission
  }, [permission, permissionEntity])

  const hasPermission = usePermissions(effectivePermission ?? [])
  const hasRoleResult = useRoles(role ?? [], false)
  const hasRole = typeof hasRoleResult === 'boolean' ? hasRoleResult : Boolean(hasRoleResult)

  const allowed = isAllowed(hasPermission, hasRole, effectivePermission, role, requireAll)
  if (!allowed) {
    return <Navigate to={ACCESS_DENIED_PATH} state={{ from: location }} replace />
  }

  return <Outlet />
}
