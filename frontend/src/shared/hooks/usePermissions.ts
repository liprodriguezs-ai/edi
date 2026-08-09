import { useMemo } from 'react';
import { useStore } from 'zustand';
import { sessionStore } from '@/shared/auth/sessionStore';

const EMPTY_ABILITIES: string[] = [];

/**
 * Hook para verificar permisos (equivalente a AclPipe)
 * @param key - Permiso o array de permisos a verificar
 * @returns boolean - true si el usuario tiene el permiso
 */
export const usePermissions = (key: string | string[]): boolean => {
    const abilities = useStore(sessionStore, (state) => state.profile?.abilities ?? EMPTY_ABILITIES);

    return useMemo(() => {
        if (!abilities?.length) {
            return false;
        }

        if (abilities.includes('*')) {
            return true;
        }

        if (typeof key === 'string') {
            return abilities.includes(key);
        }

        if (Array.isArray(key)) {
            return key.some(k => abilities.includes(k));
        }

        return false;
    }, [abilities, key]);
};

/**
 * Hook para verificar múltiples permisos individualmente
 * @param permissions - Object con permisos a verificar
 * @returns Object con el resultado de cada permiso
 * 
 * @example
 * const { canCreate, canEdit, canDelete } = useMultiplePermissions({
 *   canCreate: 'create.bonus',
 *   canEdit: 'edit.bonus', 
 *   canDelete: 'delete.bonus'
 * });
 */
export const useMultiplePermissions = <T extends Record<string, string | string[]>>(
    permissions: T
): Record<keyof T, boolean> => {
    const abilities = useStore(sessionStore, (state) => state.profile?.abilities ?? EMPTY_ABILITIES);

    return useMemo(() => {
        const result: Record<keyof T, boolean> = {} as Record<keyof T, boolean>;

        const isWildcard = abilities.includes('*');

        Object.entries(permissions).forEach(([key, permission]) => {
            if (!abilities?.length) {
                result[key as keyof T] = false;
                return;
            }

            if (isWildcard) {
                result[key as keyof T] = true;
                return;
            }

            if (typeof permission === 'string') {
                result[key as keyof T] = abilities.includes(permission);
            } else if (Array.isArray(permission)) {
                result[key as keyof T] = permission.some(p => abilities.includes(p));
            } else {
                result[key as keyof T] = false;
            }
        });

        return result;
    }, [abilities, permissions]);
};
