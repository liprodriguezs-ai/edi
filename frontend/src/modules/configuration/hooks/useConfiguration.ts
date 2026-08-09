import {
  ConfigurationResource,
  type GeneralConfig,
  type INotificationConfig,
  type IPlanInfo,
  type LogoUploadResult,
  type NotificationEventPatch,
  type NotificationTemplate,
  type OperationalConfig,
} from '@/modules/configuration/services/ConfigurationResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/**
 * Capa de datos de la Configuración del condominio (§16): encapsula las queries y
 * mutaciones contra `config/*` para que ConfigurationPage solo renderice (5-layer:
 * el componente consume el hook, no usa useQuery directo).
 */
export function useConfiguration() {
  const queryClient = useQueryClient()
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['config'] })

  const { data: general } = useQuery({
    queryKey: ['config', 'general'],
    queryFn: () => ConfigurationResource.getGeneral(),
  })
  const { data: operational } = useQuery({
    queryKey: ['config', 'operational'],
    queryFn: () => ConfigurationResource.getOperational(),
  })
  const { data: collection } = useQuery({
    queryKey: ['config', 'collection'],
    queryFn: () => ConfigurationResource.getCollectionSettings(),
  })
  const { data: plan } = useQuery({
    queryKey: ['config', 'plan'],
    queryFn: () => ConfigurationResource.getPlan(),
  })
  const { data: templates = [] } = useQuery({
    queryKey: ['config', 'notification-templates'],
    queryFn: () => ConfigurationResource.getNotificationTemplates(),
  })
  const { data: legalDocuments = [] } = useQuery({
    queryKey: ['config', 'legal'],
    queryFn: () => ConfigurationResource.getLegalDocuments(),
  })
  const {
    data: notificationConfig,
    isError: notificationsError,
    isLoading: notificationsLoading,
  } = useQuery({
    queryKey: ['config', 'notifications'],
    queryFn: () => ConfigurationResource.getNotificationConfig(),
  })

  const onError = (fallback: string) => (error: unknown) => {
    if (error instanceof ApiError) {
      const { title } = formatApiErrorMessage(error)
      toast.error(title)
    } else {
      toast.error(fallback)
    }
  }

  const saveMutation = useMutation<
    unknown,
    Error,
    { path: string; payload: Record<string, unknown> }
  >({
    mutationFn: ({ path, payload }) => ConfigurationResource.save(path, payload),
    onSuccess: () => {
      toast.success('Configuración guardada')
      void invalidate()
    },
    onError: onError('No se pudo guardar'),
  })

  const logoMutation = useMutation<LogoUploadResult, Error, File>({
    mutationFn: (file) => ConfigurationResource.uploadLogo(file),
    onSuccess: () => {
      toast.success('Logo actualizado')
      void queryClient.invalidateQueries({ queryKey: ['config', 'general'] })
    },
    onError: onError('No se pudo subir la imagen'),
  })

  const templatesMutation = useMutation<unknown, Error, NotificationTemplate[]>({
    mutationFn: (next) => ConfigurationResource.updateNotificationTemplates(next),
    onSuccess: () => {
      toast.success('Plantillas guardadas')
      void queryClient.invalidateQueries({ queryKey: ['config', 'notification-templates'] })
    },
    onError: onError('No se pudieron guardar las plantillas'),
  })

  const restoreTemplatesMutation = useMutation<unknown, Error, undefined>({
    mutationFn: () => ConfigurationResource.restoreNotificationTemplates(),
    onSuccess: () => {
      toast.success('Plantillas restauradas a los valores por defecto')
      void queryClient.invalidateQueries({ queryKey: ['config', 'notification-templates'] })
    },
    onError: onError('No se pudieron restaurar'),
  })

  const uploadLegalMutation = useMutation<
    unknown,
    Error,
    { payload: { type: string; title?: string; is_visible_to_residents?: boolean }; file: File }
  >({
    mutationFn: ({ payload, file }) => ConfigurationResource.uploadLegalDocument(payload, file),
    onSuccess: () => {
      toast.success('Documento legal subido')
      void queryClient.invalidateQueries({ queryKey: ['config', 'legal'] })
    },
    onError: onError('No se pudo subir el documento'),
  })

  const notificationsMutation = useMutation<unknown, Error, NotificationEventPatch[]>({
    mutationFn: (events) => ConfigurationResource.updateNotificationConfig(events),
    onSuccess: () => {
      toast.success('Configuración de notificaciones guardada')
      void queryClient.invalidateQueries({ queryKey: ['config', 'notifications'] })
    },
    onError: onError('No se pudo guardar la configuración de notificaciones'),
  })

  return {
    general: general as GeneralConfig | undefined,
    operational: operational as OperationalConfig | undefined,
    collection,
    plan: (plan ?? null) as IPlanInfo | null,
    templates,
    legalDocuments,
    notificationConfig: notificationConfig as INotificationConfig | undefined,
    notificationsError,
    notificationsLoading,
    saveMutation,
    logoMutation,
    templatesMutation,
    restoreTemplatesMutation,
    uploadLegalMutation,
    notificationsMutation,
  }
}
