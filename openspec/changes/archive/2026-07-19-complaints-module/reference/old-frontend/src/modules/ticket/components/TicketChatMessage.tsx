import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { downloadTicketMedia } from '@/modules/ticket/utils/downloadTicketMedia'
import type { ITicketMessage } from '@/modules/ticket/schema'
import type { IMedia } from '@/shared/interfaces/Entity'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayInitials } from '@/shared/utils/displayInitials'
import { cn } from '@/lib/utils'
import { FileText, Paperclip } from 'lucide-react'

interface TicketChatMessageProps {
  message: ITicketMessage
  isOwn: boolean
}

export function TicketChatMessage({ message, isOwn }: TicketChatMessageProps) {
  const authorName = message.user?.full_name ?? message.user?.name ?? 'Usuario'
  const profileImage = message.user?.profile_image_url
  const media = message.media ?? []

  return (
    <div
      className={cn(
        'flex items-end gap-2',
        isOwn ? 'flex-row-reverse' : 'flex-row'
      )}
    >
      {/* Avatar — only for other people's messages */}
      {!isOwn && (
        <Avatar size="sm" className="mb-0.5 shrink-0">
          {profileImage && <AvatarImage src={profileImage} alt={authorName} />}
          <AvatarFallback className="bg-muted text-muted-foreground text-[10px]">
            {displayInitials(authorName)}
          </AvatarFallback>
        </Avatar>
      )}

      {/* Message bubble */}
      <div
        className={cn(
          'flex max-w-[75%] flex-col',
          isOwn ? 'items-end' : 'items-start'
        )}
      >
        {/* Author name — only for other people's messages */}
        {!isOwn && (
          <p className="mb-0.5 ml-1 text-xs font-medium text-muted-foreground">
            {authorName}
          </p>
        )}

        {/* Bubble */}
        <div
          className={cn(
            'rounded-2xl px-3.5 py-2 text-sm leading-relaxed shadow-sm',
            isOwn
              ? 'rounded-br-sm bg-primary text-primary-foreground'
              : 'rounded-bl-sm bg-muted text-foreground'
          )}
        >
          {message.message && (
            <p className="whitespace-pre-wrap break-words">{message.message}</p>
          )}

          {/* Attachments inside bubble */}
          {media.length > 0 && (
            <div className={cn('flex flex-wrap gap-1.5', message.message && 'mt-2')}>
              {media.map((item) => (
                <MediaChip key={item.id} media={item} isOwn={isOwn} />
              ))}
            </div>
          )}
        </div>

        {/* Timestamp */}
        <p
          className={cn(
            'mt-0.5 px-1 text-[10px] text-muted-foreground/60',
            isOwn ? 'text-right' : 'text-left'
          )}
        >
          {displayFormatDate(message.created_at)}
        </p>
      </div>
    </div>
  )
}

// --- Inline media attachment chip ---

interface MediaChipProps {
  media: IMedia
  isOwn: boolean
}

function MediaChip({ media, isOwn }: MediaChipProps) {
  const isImage = media.mime_type?.startsWith('image/')
  const handleDownload = () => {
    void downloadTicketMedia(media)
  }

  if (isImage) {
    return (
      <button
        type="button"
        onClick={handleDownload}
        className="block cursor-pointer overflow-hidden rounded-lg bg-transparent p-0 text-left"
      >
        <img
          src={media.preview_url || media.original_url}
          alt={media.name}
          className="h-20 w-auto rounded-lg object-cover"
        />
      </button>
    )
  }

  return (
    <button
      type="button"
      onClick={handleDownload}
      className={cn(
        'flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1 text-left text-xs transition-colors',
        isOwn
          ? 'bg-primary-foreground/15 text-primary-foreground hover:bg-primary-foreground/25'
          : 'bg-background/60 text-foreground hover:bg-background/80'
      )}
    >
      {media.mime_type?.includes('pdf') ? (
        <FileText className="size-3.5 shrink-0" />
      ) : (
        <Paperclip className="size-3.5 shrink-0" />
      )}
      <span className="max-w-[150px] truncate">{media.file_name}</span>
    </button>
  )
}
