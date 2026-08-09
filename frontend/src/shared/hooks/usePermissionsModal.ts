import { create } from 'zustand';

interface PermissionsModalState {
    isOpen: boolean;
    message: string;
    action: string;
    openModal: (message?: string, action?: string) => void;
    closeModal: () => void;
}

/**
 * Hook para manejar el modal de permisos insuficientes (403)
 * Utiliza Zustand para estado global simple
 */
export const usePermissionsModal = create<PermissionsModalState>((set) => ({
    isOpen: false,
    message: 'No tienes permisos para realizar esta acción',
    action: 'acción solicitada',

    openModal: (message = 'No tienes permisos para realizar esta acción', action = 'acción solicitada') =>
        set({
            isOpen: true,
            message,
            action
        }),

    closeModal: () =>
        set({
            isOpen: false,
            message: 'No tienes permisos para realizar esta acción',
            action: 'acción solicitada'
        }),
}));
