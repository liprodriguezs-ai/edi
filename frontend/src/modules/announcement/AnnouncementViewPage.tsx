import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ANNOUNCEMENT_STATUS, type IAnnouncement } from '@/modules/announcement/schema'
import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { MediaAttachmentCard } from '@/shared/components/molecules/MediaAttachmentCard'
import { RichTextContent } from '@/shared/components/richtext/RichTextContent'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CalendarDays } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

/**
 * Vista de lectura de un aviso como PÁGINA (no diálogo) — para AMBOS roles (residente y
 * gestor), ya que el contenido puede ser largo. Muestra el contenido enriquecido, los
 * metadatos y los adjuntos descargables. Al abrir, marca como leído para el residente
 * (no para gestores/autores) e invalida el feed.
 */
export const AnnouncementViewPage = () => {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canManage = usePermissions('update.announcement')

  const {
    entity: announcement,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IAnnouncement>({
    queryKey: ['announcement', 'detail', id],
    // Incluye las relaciones que el lector muestra: chips de tipo/prioridad/estado + adjuntos.
    queryFn: () =>
      AnnouncementResource.show(id as string, { include: 'type,priority,status,media' }),
    enabled: Boolean(id),
  })

  const markRead = useMutation({
    mutationFn: (announcementId: string) => AnnouncementResource.markAsRead(announcementId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['announcement', 'board'] }),
  })

  // Marca como leído al abrir — SOLO residentes (no gestores/autores) y solo si está publicado.
  // El ref evita re-disparos si el aviso se refetchea.
  const markedRef = useRef(false)
  useEffect(() => {
    if (markedRef.current || !id || !announcement || canManage) return
    if (announcement.announcement_status_id === ANNOUNCEMENT_STATUS.PUBLISHED) {
      markedRef.current = true
      markRead.mutate(id)
    }
  }, [id, announcement, canManage, markRead])

  const attachments = announcement?.media ?? []

  return (
    <div className='mx-auto w-full max-w-5xl space-y-6'>
      <div className='flex items-center gap-3'>
        <Button
          variant='ghost'
          size='icon'
          className='size-8'
          onClick={() => navigate('/announcement')}
          aria-label='Volver a avisos'
        >
          <ArrowLeft className='size-4' />
        </Button>
        <PageHeader title='Aviso' description='Comunicado del condominio' />
      </div>

      {isLoading ? (
        <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando aviso…</p>
      ) : isError || !announcement ? (
        <EntityNotFound
          variant={notFound ? 'notFound' : 'error'}
          title={notFound ? 'Aviso no encontrado' : undefined}
          backTo='/announcement'
          backLabel='Volver a avisos'
          onRetry={notFound ? undefined : refetch}
        />
      ) : (
        <article className='rounded-[16px] border border-border bg-card p-6 shadow-sm md:p-8'>
          <h1 className='text-[22px] font-semibold leading-snug'>{announcement.title}</h1>

          <div className='mt-2 flex flex-wrap items-center gap-2'>
            {announcement.type?.name && <Badge variant='secondary'>{announcement.type.name}</Badge>}
            {announcement.priority?.name && (
              <Badge variant='outline'>{announcement.priority.name}</Badge>
            )}
            {announcement.publish_at && (
              <span className='flex items-center gap-1 text-[12px] text-[var(--ink-3)]'>
                <CalendarDays className='size-3.5' />
                {displayFormatDate(announcement.publish_at, 'mediumDate')}
              </span>
            )}
          </div>

          <div className='mt-5 border-t border-border pt-5'>
            <RichTextContent
              html={announcement.content_html}
              fallbackText={announcement.content}
              className='text-[15px] leading-relaxed text-[var(--ink-1)]'
            />
          </div>

          {attachments.length > 0 && (
            <div className='mt-6 space-y-1.5 border-t border-border pt-4'>
              <p className='text-[11px] font-semibold uppercase tracking-[0.06em] text-[var(--ink-3)]'>
                Adjuntos
              </p>
              {attachments.map((media) => (
                <MediaAttachmentCard key={media.id} media={media} />
              ))}
            </div>
          )}
        </article>
      )}
    </div>
  )
}
