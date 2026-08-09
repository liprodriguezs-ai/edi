import { Button } from '@/components/ui/button'
import { CHAT_CHANNEL_TYPE, type IChatMessage } from '@/modules/chat/schema'
import {
  ChatChannelResource,
  ChatMessageResource,
  chatChannelsQueryOptions,
} from '@/modules/chat/services/ChatResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { sessionStore } from '@/shared/auth/sessionStore'
import { ChannelSidebar } from '@/modules/chat/components/ChannelSidebar'
import { displayChannelName } from '@/modules/chat/helpers/channelDisplay'
import { ChatReportsModal } from '@/modules/chat/components/ChatReportsModal'
import { ChatMessageBubble } from '@/modules/chat/components/ChatMessageBubble'
import { MessageComposer } from '@/modules/chat/components/MessageComposer'
import { ChannelAdminModal } from '@/modules/chat/components/ChannelAdminModal'
import { ReportMessageModal } from '@/modules/chat/components/ReportMessageModal'
import { useSendMessage } from '@/modules/chat/hooks/useSendMessage'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useMultiplePermissions, usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Flag, Settings } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useStore } from 'zustand'

/**
 * Texto plano del error al cargar mensajes. `formatApiErrorMessage` devuelve
 * `{title, description}` (formato de toast), pero acá se pinta dentro del panel.
 */
function messagesErrorLabel(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return 'Revisa tu conexión e inténtalo de nuevo.'
  }
  const { title, description } = formatApiErrorMessage(error)
  return description ?? title
}

/**
 * Chat comunitario (§13): canales del condominio + mensajes con refresco
 * periódico. Los moderadores pueden ocultar mensajes (nunca se borran).
 */
export const ChatPage = () => {
  const queryClient = useQueryClient()
  const currentUserId = useStore(sessionStore, (s) => s.profile?.user?.id)
  const canModerate = usePermissions('update.chat_message')
  const channelPerms = useMultiplePermissions({
    canCreate: 'create.chat_channel',
    canManage: 'update.chat_channel',
  })
  const canAdminChannels = channelPerms.canCreate || channelPerms.canManage

  const [searchParams] = useSearchParams()
  const deepLinkChannelId = searchParams.get('channel')

  const [selectedChannelId, setSelectedChannelId] = useState<string | null>(null)
  const [appliedDeepLink, setAppliedDeepLink] = useState<string | null>(null)
  const [channelAdminOpen, setChannelAdminOpen] = useState(false)
  const [reportsOpen, setReportsOpen] = useState(false)
  const [reportTarget, setReportTarget] = useState<IChatMessage | null>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  const { data: channels = [], isLoading: channelsLoading } = useQuery(chatChannelsQueryOptions)

  // Deep-link de las notificaciones de chat (`/chat-channel?channel=<id>`): sin
  // esto el destinatario caía SIEMPRE en channels[0] —el canal más antiguo, que
  // suele ser "Avisos oficiales", vacío y de solo lectura— y creía que su mensaje
  // no había llegado.
  //
  // Se ajusta el estado DURANTE el render (patrón oficial de React para "estado
  // que depende de un valor externo cambiante") en vez de con un efecto: así una
  // notificación posterior de OTRO canal vuelve a mandar aunque el usuario ya
  // haya elegido un canal a mano, y no se dispara un render en cascada.
  // Sólo se aplica si el canal está en la lista accesible; si no lo está (canal
  // borrado o sin acceso) se ignora y queda el canal por defecto.
  if (deepLinkChannelId !== appliedDeepLink && channels.some((c) => c.id === deepLinkChannelId)) {
    setAppliedDeepLink(deepLinkChannelId)
    setSelectedChannelId(deepLinkChannelId)
  }

  const channelId = selectedChannelId ?? channels[0]?.id ?? null
  const channel = channels.find((c) => c.id === channelId)
  const isDirectChannel = channel?.chat_channel_type_id === CHAT_CHANNEL_TYPE.DIRECT

  const {
    data: messages = [],
    isPending: messagesPending,
    isError: messagesFailed,
    error: messagesError,
  } = useQuery({
    queryKey: ['chat', 'messages', channelId],
    queryFn: async () => {
      const { data } = await ChatMessageResource.search({
        limit: 100,
        filters: [{ field: 'chat_channel_id', operator: '=', value: channelId }],
        includes: [{ relation: 'user' }, { relation: 'reactions' }, { relation: 'media' }],
        sort: [{ field: 'created_at', direction: 'desc' }],
      })
      return [...data].reverse()
    },
    enabled: Boolean(channelId),
    refetchInterval: 10 * 1000,
  })

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: 'end' })
  }, [messages.length, channelId])

  const sendMutation = useSendMessage(channelId, () => {})

  const hideMutation = useMutation({
    mutationFn: (message: IChatMessage) => ChatMessageResource.hide(message.id),
    onSuccess: () => {
      toast.success('Mensaje ocultado')
      void queryClient.invalidateQueries({ queryKey: ['chat', 'messages', channelId] })
    },
    onError: (error) =>
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo ocultar'),
  })

  const unhideMutation = useMutation({
    mutationFn: (message: IChatMessage) => ChatMessageResource.unhide(message.id),
    onSuccess: () => {
      toast.success('Mensaje restaurado')
      void queryClient.invalidateQueries({ queryKey: ['chat', 'messages', channelId] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo restaurar'
      ),
  })

  const editMutation = useMutation({
    mutationFn: ({ id, body }: { id: string; body: string }) => ChatMessageResource.edit(id, body),
    onSuccess: () => {
      toast.success('Mensaje editado')
      void queryClient.invalidateQueries({ queryKey: ['chat', 'messages', channelId] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo editar el mensaje'
      ),
  })

  const reactMutation = useMutation({
    mutationFn: ({ message, hasReacted }: { message: IChatMessage; hasReacted: boolean }) =>
      hasReacted ? ChatMessageResource.unreact(message.id) : ChatMessageResource.react(message.id),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ['chat', 'messages', channelId] }),
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo reaccionar'
      ),
  })

  // Abre (o recupera) el chat 1:1 con el autor de un mensaje y lo selecciona.
  const directMutation = useMutation({
    mutationFn: (message: IChatMessage) => ChatChannelResource.direct(message.user_id as string),
    onSuccess: async (directChannel) => {
      await queryClient.invalidateQueries({ queryKey: ['chat', 'channels'] })
      setSelectedChannelId(directChannel.id)
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo abrir el chat directo'
      ),
  })

  const messagesErrorText = messagesErrorLabel(messagesError)

  const anyActionPending =
    editMutation.isPending ||
    reactMutation.isPending ||
    hideMutation.isPending ||
    unhideMutation.isPending

  const handleReport = useCallback((m: IChatMessage) => setReportTarget(m), [])
  const handleHide = useCallback((m: IChatMessage) => hideMutation.mutate(m), [hideMutation])
  const handleUnhide = useCallback((m: IChatMessage) => unhideMutation.mutate(m), [unhideMutation])
  const handleReact = useCallback(
    (m: IChatMessage, hasReacted: boolean) => reactMutation.mutate({ message: m, hasReacted }),
    [reactMutation]
  )
  const handleEdit = useCallback(
    (id: string, body: string) => editMutation.mutateAsync({ id, body }),
    [editMutation]
  )
  const handleDirectMessage = useCallback(
    (m: IChatMessage) => directMutation.mutate(m),
    [directMutation]
  )

  const handleSend = (
    payload: Parameters<typeof sendMutation.mutateAsync>[0]
  ): Promise<unknown> => {
    // Reject (not resolve) when the send is skipped so the composer keeps the draft
    // instead of clearing it; mutateAsync resolves on success / rejects on failure.
    if (!channelId || sendMutation.isPending) {
      return Promise.reject(new Error('No se puede enviar el mensaje en este momento.'))
    }
    return sendMutation.mutateAsync(payload)
  }

  return (
    <div className='flex h-full min-h-[60vh] flex-col space-y-4'>
      <PageHeader
        title='Chat Comunitario'
        description='Canales de conversación del condominio'
        actions={
          <>
            <PermissionGuard permission='update.chat_message_report'>
              <Button
                variant='outline'
                className='rounded-full'
                onClick={() => setReportsOpen(true)}
              >
                <Flag className='size-4' />
                Reportes
              </Button>
            </PermissionGuard>
            {canAdminChannels && (
              <Button className='rounded-full' onClick={() => setChannelAdminOpen(true)}>
                <Settings className='size-4' />
                Canales
              </Button>
            )}
          </>
        }
      />

      <div className='grid min-h-0 flex-1 grid-cols-1 gap-3.5 lg:grid-cols-[260px_1fr]'>
        <ChannelSidebar
          channels={channels}
          isLoading={channelsLoading}
          activeId={channelId}
          currentUserId={currentUserId}
          onSelect={setSelectedChannelId}
        />

        {/* mensajes */}
        <div className='flex min-h-0 flex-col overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
          <div className='border-b border-[var(--line-2)] px-4 py-3'>
            <h3 className='text-sm font-semibold'>
              {channel ? displayChannelName(channel, currentUserId) : 'Selecciona un canal'}
            </h3>
            {channel?.description ? (
              <p className='text-[12px] text-[var(--ink-3)]'>{channel.description}</p>
            ) : null}
          </div>

          <div className='min-h-[40vh] flex-1 space-y-3 overflow-y-auto px-4 py-4'>
            {/* Un fallo de carga NO debe verse igual que un canal vacío: antes
                cualquier 403/500/timeout pintaba "Sin mensajes" y el usuario creía
                que no le había llegado nada. */}
            {messagesFailed ? (
              <p className='py-10 text-center text-[13px] text-destructive'>
                No se pudieron cargar los mensajes. {messagesErrorText}
              </p>
            ) : channelId && messagesPending ? (
              <p className='py-10 text-center text-[13px] text-[var(--ink-3)]'>
                Cargando mensajes…
              </p>
            ) : messages.length === 0 ? (
              <p className='py-10 text-center text-[13px] text-[var(--ink-3)]'>
                {channelId
                  ? 'Sin mensajes — escribe el primero.'
                  : 'Selecciona un canal para conversar.'}
              </p>
            ) : (
              messages.map((message) => (
                <ChatMessageBubble
                  key={message.id}
                  message={message}
                  currentUserId={currentUserId}
                  canModerate={canModerate}
                  pending={anyActionPending}
                  onReport={handleReport}
                  onHide={handleHide}
                  onUnhide={handleUnhide}
                  onReact={handleReact}
                  onEdit={handleEdit}
                  onDirectMessage={isDirectChannel ? undefined : handleDirectMessage}
                />
              ))
            )}
            <div ref={bottomRef} />
          </div>

          <MessageComposer
            channelId={channelId}
            canWrite={channel?.can_write ?? false}
            pending={sendMutation.isPending}
            onSend={handleSend}
          />
        </div>
      </div>

      <ChannelAdminModal
        isOpen={channelAdminOpen}
        channels={channels}
        onClose={() => setChannelAdminOpen(false)}
        onChanged={() => void queryClient.invalidateQueries({ queryKey: ['chat', 'channels'] })}
      />

      {/* reportar mensaje a moderación */}
      <ReportMessageModal target={reportTarget} onClose={() => setReportTarget(null)} />

      <ChatReportsModal isOpen={reportsOpen} onClose={() => setReportsOpen(false)} />
    </div>
  )
}
