import type { ERoleUserSlug } from '@/shared/interfaces/Entity'
import type { ReactNode } from 'react'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useRoles } from '@/shared/hooks/useRoles'

interface PermissionGuardProps {
  children: ReactNode;
  permission?: string | string[];
  role?: ERoleUserSlug | ERoleUserSlug[];
  uniqueRole?: boolean;
  fallback?: ReactNode;
  requireAll?: boolean; // Si es true, requiere TODOS los permisos/roles
  isSystemOwner?: boolean;
}

/**
 * Componente para proteger contenido basado en permisos y/o roles
 * Equivalente a usar *ngIf con pipes acl y aclr en Angular
 */
export const PermissionGuard = ({
  children,
  permission,
  role,
  uniqueRole = false,
  fallback = null,
  requireAll = false,
 /*  isSystemOwner = false, */
}: PermissionGuardProps) => {
  const hasPermission = usePermissions(permission || []);
  const hasRole = useRoles(role || [], uniqueRole);

  // Si no se especifica ni permiso ni rol, mostrar contenido
  if (!permission && !role) {
    return <>{children}</>;
  }
/* 
  if (isSystemOwner && hasPermission && hasRole) {
    return <>{children}</>;
  } */

  // Solo verificar permisos
  if (permission && !role) {
    return hasPermission ? <>{children}</> : <>{fallback}</>;
  }

  // Solo verificar roles
  if (role && !permission) {
    const roleResult = typeof hasRole === 'boolean' ? hasRole : !!hasRole;
    return roleResult ? <>{children}</> : <>{fallback}</>;
  }

  // Verificar ambos: permisos Y roles
  if (permission && role) {
    const roleResult = typeof hasRole === 'boolean' ? hasRole : !!hasRole;
    
    if (requireAll) {
      // Requiere AMBOS: permiso Y rol
      return (hasPermission && roleResult) ? <>{children}</> : <>{fallback}</>;
    } else {
      // Requiere AL MENOS UNO: permiso O rol
      return (hasPermission || roleResult) ? <>{children}</> : <>{fallback}</>;
    }
  }

  return <>{fallback}</>;
};
