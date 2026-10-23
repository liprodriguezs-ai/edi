import type { IMedia } from '@/shared/interfaces/Entity'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import { Download } from 'lucide-react'

interface MediaAttachmentCardProps {
  media: IMedia
}

/**
 * Tarjeta de descarga para un adjunto genérico (Spatie Media Library) —
 * nombre/ícono + descarga vía URL firmada. Compartida entre el chat
 * (`ChatMessageBubble`) y la vista de aviso (`AnnouncementViewPage`) para no
 * duplicar el patrón de descarga.
 */
export const MediaAttachmentCard = ({ media }: MediaAttachmentCardProps) => {
  return (
    <button
      type='button'
      className='flex w-full items-center gap-2 rounded-none border border-border bg-[var(--surface-2)] px-3 py-2 text-left text-[13px] hover:border-[var(--ink-3)]'
      onClick={() => downloadMediaWithSignedUrl({ mediaId: media.id, fileName: media.file_name })}
    >
      <Download className='size-4 flex-none text-[var(--ink-3)]' />
      <span className='truncate'>{media.name || media.file_name}</span>
    </button>
  )
}
