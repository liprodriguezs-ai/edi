import type { IChatMessageReport } from '@/modules/chat/schema'
import { ChatMessageReportResource, ChatMessageResource } from '@/modules/chat/services/ChatResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/** Cola de moderación del chat (§13.6): reportes pendientes, descartar u ocultar el mensaje. */
export function useChatReports(enabled: boolean) {
  const queryClient = useQueryClient()

  const { data: reports = [], isLoading } = useQuery({
    queryKey: ['chat', 'reports'],
    queryFn: async () => {
      const { data } = await ChatMessageReportResource.search({
        limit: 100,
        filters: [{ field: 'status', operator: '=', value: 'pending' }],
        includes: [{ relation: 'message' }, { relation: 'message.user' }, { relation: 'reporterUser' }],
        sort: [{ field: 'created_at', direction: 'desc' }],
      })
      return data
    },
    enabled,
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['chat', 'reports'] })
    void queryClient.invalidateQueries({ queryKey: ['chat', 'messages'] })
  }

  const dismissMutation = useMutation({
    mutationFn: (report: IChatMessageReport) => ChatMessageReportResource.dismiss(report.id),
    onSuccess: () => { toast.success('Reporte descartado'); refresh() },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo descartar'),
  })

  const hideMutation = useMutation({
    mutationFn: (report: IChatMessageReport) => ChatMessageResource.hide(report.chat_message_id),
    onSuccess: () => { toast.success('Mensaje ocultado'); refresh() },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo ocultar'),
  })

  return { reports, isLoading, dismissMutation, hideMutation }
}
