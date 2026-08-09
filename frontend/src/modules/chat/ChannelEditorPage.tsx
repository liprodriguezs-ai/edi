import { Button } from '@/components/ui/button'
import { ChannelForm } from '@/modules/chat/components/ChannelForm'
import type { IChatChannel } from '@/modules/chat/schema'
import { ChatChannelResource } from '@/modules/chat/services/ChatResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

const CHANNELS_QUERY_KEY = ['chat', 'channels'] as const

/**
 * Página de alta/edición de un canal del condominio. Reemplaza al modal angosto anterior:
 * al ser una página dedicada, el formulario dispone de todo el ancho para su alcance de
 * lectura y escritura. En edición carga el canal por id (con estados de carga / no
 * encontrado). El selector "Copiar config de…" se alimenta de una query LIGERA (sin includes)
 * que solo dispara al abrir el dropdown, y se siembra con la caché del chat para que venir
 * desde ahí no genere ningún fetch.
 */
export const ChannelEditorPage = () => {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isEdit = Boolean(id)

  // Copy-sources: lista ligera SIN includes (solo alimenta "Copiar config de…"). No se dispara
  // hasta la 1ª apertura del dropdown; si el chat ya cacheó la lista completa la usamos como
  // seed (su forma con `members` extra es compatible) → viniendo del chat no hay fetch alguno.
  const [copyEnabled, setCopyEnabled] = useState(false)
  const {
    data: copySources = [],
    isFetching: copySourcesFetching,
    isSuccess: copySourcesLoaded,
  } = useQuery({
    queryKey: ['chat', 'channels', 'copy-sources'],
    queryFn: async () => {
      const { data } = await ChatChannelResource.search({
        limit: 100,
        filters: [{ field: 'is_archived', operator: '=', value: false }],
        sort: [{ field: 'created_at', direction: 'asc' }],
      })
      return data
    },
    enabled: copyEnabled,
    initialData: () => queryClient.getQueryData<IChatChannel[]>(CHANNELS_QUERY_KEY),
    initialDataUpdatedAt: () => queryClient.getQueryState(CHANNELS_QUERY_KEY)?.dataUpdatedAt,
    staleTime: 30 * 1000,
  })

  const {
    entity: channel,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IChatChannel>({
    queryKey: ['chat', 'channel', id],
    queryFn: () => ChatChannelResource.show(id as string),
    enabled: isEdit,
  })

  const goToChat = () => navigate('/chat-channel')

  const handleSaved = () => {
    void queryClient.invalidateQueries({ queryKey: CHANNELS_QUERY_KEY })
    // El detalle se cachea con staleTime; sin esto, guardar y re-entrar a editar el mismo canal
    // mostraría datos viejos.
    if (id) void queryClient.invalidateQueries({ queryKey: ['chat', 'channel', id] })
    goToChat()
  }

  const formProps = {
    channels: copySources,
    onCopySourcesOpen: () => setCopyEnabled(true),
    copySourcesLoading: copySourcesFetching,
    copySourcesLoaded,
    onSaved: handleSaved,
    onCancel: goToChat,
  }

  const renderBody = () => {
    if (!isEdit) {
      return <ChannelForm {...formProps} />
    }
    if (isLoading) {
      return <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando canal…</p>
    }
    if (isError || !channel) {
      return (
        <EntityNotFound
          variant={notFound ? 'notFound' : 'error'}
          title={notFound ? 'Canal no encontrado' : undefined}
          backTo='/chat-channel'
          backLabel='Volver al chat'
          onRetry={notFound ? undefined : refetch}
        />
      )
    }
    return <ChannelForm channel={channel} {...formProps} />
  }

  return (
    <div className='mx-auto w-full max-w-5xl space-y-6'>
      <div className='flex items-center gap-3'>
        <Button
          variant='ghost'
          size='icon'
          className='size-8'
          onClick={goToChat}
          aria-label='Volver al chat'
        >
          <ArrowLeft className='size-4' />
        </Button>
        <PageHeader
          title={isEdit ? 'Editar canal' : 'Nuevo canal'}
          description={
            isEdit
              ? 'Modifica el canal, su alcance y quién puede escribir'
              : 'Crea un canal de conversación para el condominio'
          }
        />
      </div>

      {renderBody()}
    </div>
  )
}
