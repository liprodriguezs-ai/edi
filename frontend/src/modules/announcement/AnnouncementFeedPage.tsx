import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { useAnnouncementFeed } from '@/modules/announcement/hooks/useAnnouncementFeed'
import { type IAnnouncement } from '@/modules/announcement/schema'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Megaphone, Paperclip } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

/** Tarjeta de un aviso en el feed del residente. */
function FeedCard({
  announcement,
  onOpen,
}: {
  announcement: IAnnouncement
  onOpen: (_a: IAnnouncement) => void
}) {
  const attachmentCount = announcement.media?.length ?? 0
  const isUnread = !announcement.is_read

  return (
    <Card
      role='button'
      tabIndex={0}
      onClick={() => onOpen(announcement)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onOpen(announcement)
        }
      }}
      aria-label={`Abrir aviso ${announcement.title}`}
      className={`cursor-pointer gap-3 py-4 shadow-sm transition hover:border-[var(--ink-3)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--green)] ${
        isUnread ? 'border-[var(--green)]' : ''
      }`}
    >
      <CardHeader className='gap-0 px-4'>
        <div className='flex items-center gap-2'>
          <span className='grid size-7 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[var(--green)]'>
            <Megaphone className='size-3.5' />
          </span>
          {announcement.type?.name && <Badge variant='secondary'>{announcement.type.name}</Badge>}
          {announcement.priority?.name && (
            <Badge variant='outline'>{announcement.priority.name}</Badge>
          )}
          {isUnread && <Badge className='ml-auto bg-[var(--green)] text-white'>Nuevo</Badge>}
        </div>
      </CardHeader>
      <CardContent className='px-4'>
        <h3 className='text-[14.5px] font-semibold'>{announcement.title}</h3>
        {/* Preview en texto PLANO (nunca HTML) recortado a 3 líneas. */}
        <p className='mt-1 line-clamp-3 text-[12.5px] text-[var(--ink-3)]'>
          {announcement.content}
        </p>
        <div className='mt-2 flex items-center gap-2 text-[11.5px] text-[var(--ink-4)]'>
          <span>
            {announcement.publish_at
              ? displayFormatDate(announcement.publish_at, 'mediumDate')
              : ''}
          </span>
          {attachmentCount > 0 && (
            <span className='ml-auto flex items-center gap-1'>
              <Paperclip className='size-3' />
              {attachmentCount}
            </span>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * Feed de avisos del residente (§12.6): tarjetas de los avisos publicados del condominio,
 * urgentes primero, marcando los no leídos. Abrir uno navega a su página de lectura
 * (`/announcement/:id`), que marca como leído.
 */
export const AnnouncementFeedPage = () => {
  const { announcements, isLoading } = useAnnouncementFeed()
  const navigate = useNavigate()

  return (
    <div className='space-y-5'>
      <PageHeader title='Avisos' description='Comunicados del condominio' />

      {isLoading ? (
        <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando avisos…</p>
      ) : announcements.length === 0 ? (
        <p className='rounded-[14px] border border-border bg-card px-6 py-12 text-center text-sm text-[var(--ink-3)]'>
          No hay avisos por ahora.
        </p>
      ) : (
        <div className='grid grid-cols-1 gap-3.5 md:grid-cols-2 lg:grid-cols-3'>
          {announcements.map((announcement) => (
            <FeedCard
              key={announcement.id}
              announcement={announcement}
              onOpen={(a) => navigate(`/announcement/${a.id}`)}
            />
          ))}
        </div>
      )}
    </div>
  )
}
