import {
  UserResource,
  type NotificationPreferences,
  type NotificationPreferencesPayload,
} from '@/modules/user/services/UserResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/** Categorías canónicas de notificación (§17.3) con su etiqueta para el usuario. */
export const NOTIFICATION_CATEGORIES: ReadonlyArray<{ key: string; label: string }> = [
  { key: 'collections', label: 'Cobranza' },
  { key: 'announcements', label: 'Avisos' },
  { key: 'access', label: 'Accesos' },
  { key: 'chat', label: 'Chat' },
  { key: 'projects', label: 'Proyectos' },
  { key: 'reports', label: 'Reportes' },
  { key: 'system', label: 'Sistema' },
]

export const NOTIFICATION_CHANNELS: ReadonlyArray<{ key: 'push' | 'email' | 'sms'; label: string }> = [
  { key: 'push', label: 'Push' },
  { key: 'email', label: 'Correo' },
  { key: 'sms', label: 'SMS' },
]

/** Preferencias de notificación del usuario (§17.3): lectura + guardado. */
export function useNotificationPreferences() {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery<NotificationPreferences>({
    queryKey: ['notification-preferences'],
    queryFn: () => UserResource.getNotificationPreferences(),
  })

  const updateMutation = useMutation({
    mutationFn: (payload: NotificationPreferencesPayload) => UserResource.updateNotificationPreferences(payload),
    onSuccess: (result) => {
      toast.success('Preferencias guardadas')
      queryClient.setQueryData(['notification-preferences'], result)
    },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudieron guardar las preferencias'),
  })

  return { preferences: data, isLoading, updateMutation }
}
