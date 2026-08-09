import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import type { ITicket } from '@/modules/ticket/schema'
import { useTicketChat } from '@/modules/ticket/hooks/useTicketChat'
import { downloadTicketMedia } from '@/modules/ticket/utils/downloadTicketMedia'
import { useTicketDetailStore } from '@/modules/ticket/store/ticketDetailStore'
import { useSession } from '@/shared/auth/useSession'
import type { IMedia } from '@/shared/interfaces/Entity'
import { displayInitials } from '@/shared/utils/displayInitials'
import { formatDistanceToNow, parseISO } from 'date-fns'
import { es } from 'date-fns/locale'
import { Paperclip, Send, X, FileIcon } from 'lucide-react'
import { type ClipboardEvent, type DragEvent, type KeyboardEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react'

interface TicketTimelineProps {
  ticket: ITicket
}

export function TicketTimeline({ ticket }: TicketTimelineProps) {
  const bump = useTicketDetailStore((s) => s.bump)
  const { messages, isLoading, isSending, sendMessage, scrollRef, bottomRef } = useTicketChat(ticket.id)
  const { user } = useSession()
  const [messageText, setMessageText] = useState('')
  const [pendingFiles, setPendingFiles] = useState<File[]>([])
  const [isDragOver, setIsDragOver] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const dragDepthRef = useRef(0)

  const addFiles = useCallback((files: FileList | File[]) => {
    const newFiles = Array.from(files)
    if (newFiles.length === 0) return
    setPendingFiles((prev) => [...prev, ...newFiles])
  }, [])

  const removeFile = useCallback((index: number) => {
    setPendingFiles((prev) => prev.filter((_, i) => i !== index))
  }, [])

  const handleSend = useCallback(async () => {
    if ((!messageText.trim() && pendingFiles.length === 0) || isSending) return
    const text = messageText
    const files = pendingFiles.length > 0 ? pendingFiles : undefined
    setMessageText('')
    setPendingFiles([])
    await sendMessage(text, files)
    bump()
  }, [messageText, pendingFiles, isSending, sendMessage, bump])

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLTextAreaElement>) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault()
        void handleSend()
      }
    },
    [handleSend],
  )

  const handlePaste = useCallback(
    (event: ClipboardEvent<HTMLTextAreaElement>) => {
      const items = event.clipboardData?.files
      if (items && items.length > 0) {
        event.preventDefault()
        addFiles(items)
      }
    },
    [addFiles],
  )

  const isFileDrag = useCallback((event: DragEvent<HTMLDivElement>) => {
    const types = event.dataTransfer?.types
    if (!types) return false
    return Array.from(types).includes('Files')
  }, [])

  const handleDragEnter = useCallback(
    (event: DragEvent<HTMLDivElement>) => {
      if (!isFileDrag(event)) return
      event.preventDefault()
      event.stopPropagation()
      dragDepthRef.current += 1
      setIsDragOver(true)
    },
    [isFileDrag],
  )

  const handleDragOver = useCallback(
    (event: DragEvent<HTMLDivElement>) => {
      if (!isFileDrag(event)) return
      event.preventDefault()
      event.stopPropagation()
      event.dataTransfer.dropEffect = 'copy'
      if (!isDragOver) {
        setIsDragOver(true)
      }
    },
    [isDragOver, isFileDrag],
  )

  const handleDragLeave = useCallback((event: DragEvent<HTMLDivElement>) => {
    if (dragDepthRef.current <= 0) {
      return
    }
    event.preventDefault()
    event.stopPropagation()
    dragDepthRef.current = Math.max(0, dragDepthRef.current - 1)
    if (dragDepthRef.current === 0) {
      setIsDragOver(false)
    }
  }, [])

  const handleDrop = useCallback(
    (event: DragEvent<HTMLDivElement>) => {
      if (!isFileDrag(event)) return
      event.preventDefault()
      event.stopPropagation()
      dragDepthRef.current = 0
      setIsDragOver(false)

      const files = event.dataTransfer?.files
      if (files && files.length > 0) {
        addFiles(files)
      }
    },
    [addFiles, isFileDrag],
  )

  const totalEntries = messages.length + 1

  return (
    <div
      className="flex h-full min-h-0 flex-1 flex-col"
      onDragEnter={handleDragEnter}
      onDragOver={handleDragOver}
      onDragLeave={handleDragLeave}
      onDrop={handleDrop}
    >
      {/* Scrollable area */}
      <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 pt-4 lg:px-6 lg:pt-6">
        {isLoading ? (
          <TimelineSkeleton />
        ) : (
          <div className="space-y-0 lg:space-y-0">
            {/* First entry: ticket creation */}
            <TimelineEntry
              userName={ticket.user?.full_name ?? ticket.user?.name ?? 'Usuario'}
              profileImage={ticket.user?.profile_image_url}
              date={ticket.created_at}
              content={ticket.description ?? 'Sin descripción'}
              media={ticket.media}
              isFirst
              isLast={totalEntries === 1}
              isOwn={ticket.user_id === user?.id}
              statusColor={ticket.ticket_status?.color}
            />

            {/* Message entries */}
            {messages.map((msg, index) => (
              <TimelineEntry
                key={msg.id}
                userName={msg.user?.full_name ?? msg.user?.name ?? 'Usuario'}
                profileImage={msg.user?.profile_image_url}
                date={msg.created_at}
                content={msg.message ?? ''}
                media={msg.media}
                isOwn={msg.user_id === user?.id}
                isLast={index === messages.length - 1}
              />
            ))}
            <div ref={bottomRef} className="mt-4 lg:mt-6" />
          </div>
        )}
      </div>

      {/* Input area */}
      <div className="shrink-0 border-t bg-background px-3 py-2 lg:px-6 lg:py-3">
        <div
          className={cn(
            'rounded-xl border border-transparent px-1 py-1 transition-colors',
            isDragOver && 'border-primary/40 bg-primary/5',
          )}
        >
        {/* File previews */}
        {pendingFiles.length > 0 && (
          <div className="flex flex-wrap gap-2 rounded-lg border border-dashed border-border/60 bg-muted/30 p-2 mb-2">
            {pendingFiles.map((file, index) => (
              <FilePreview key={`${file.name}-${index}`} file={file} onRemove={() => removeFile(index)} />
            ))}
          </div>
        )}

        {isDragOver && (
          <p className="mb-2 text-center text-xs text-primary">
            Suelta el archivo aquí para adjuntarlo
          </p>
        )}

        <div className="flex items-end gap-2">
          <label className="inline-flex items-center justify-center size-9 shrink-0 rounded-full border border-input bg-background transition-colors hover:bg-accent cursor-pointer">
            <Paperclip className="size-4 text-muted-foreground" />
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar"
              className="absolute h-0 w-0 overflow-hidden opacity-0"
              tabIndex={-1}
              aria-hidden="true"
              onChange={(e) => {
                if (e.target.files) addFiles(e.target.files)
                e.target.value = ''
              }}
            />
          </label>

          <Textarea
            value={messageText}
            onChange={(e) => setMessageText(e.target.value)}
            onKeyDown={handleKeyDown}
            onPaste={handlePaste}
            placeholder="Escribe tu mensaje..."
            rows={1}
            className="min-h-[36px] resize-none bg-muted/30 border-0 focus-visible:ring-1 focus-visible:ring-primary/30 rounded-2xl px-4 py-2 text-sm flex-1"
            disabled={isSending}
          />

          <Button
            size="icon"
            type="button"
            onClick={() => void handleSend()}
            disabled={isSending || (!messageText.trim() && pendingFiles.length === 0)}
            className="size-9 shrink-0 rounded-full"
          >
            <Send className="size-4" />
          </Button>
        </div>

        <p className="text-[10px] text-muted-foreground/50 text-center mt-1">
          Enter para enviar · Shift+Enter para salto de línea
        </p>
        </div>
      </div>
    </div>
  )
}

// --- Timeline Entry (responsive: chat-style on mobile, timeline-style on desktop) ---

interface TimelineEntryProps {
  userName: string
  profileImage?: string
  date?: string
  content: string
  media?: IMedia[]
  isOwn?: boolean
  isFirst?: boolean
  isLast?: boolean
  statusColor?: string
}

function TimelineEntry({
  userName,
  profileImage,
  date,
  content,
  media,
  isOwn = false,
  isFirst,
  isLast,
  statusColor,
}: TimelineEntryProps) {
  const relativeDate = date ? formatRelativeDate(date) : ''
  const hasContent = content.trim().length > 0
  const hasMedia = media && media.length > 0

  return (
      <div
        className={cn(
          'flex mb-3',
          isOwn ? 'justify-end' : 'justify-start',
          isLast && 'mb-0',
        )}
      >
        <div className={cn('flex gap-2 max-w-[85%] lg:max-w-[60%]', isOwn && 'flex-row-reverse')}>
          {/* Small avatar */}
          <Avatar className="size-7 shrink-0 mt-0.5">
            {profileImage && <AvatarImage src={profileImage} alt={userName} />}
            <AvatarFallback className="bg-primary/10 text-primary text-[9px] font-medium">
              {displayInitials(userName)}
            </AvatarFallback>
          </Avatar>

          {/* Bubble */}
          <div className="min-w-0">
            {/* Name + time */}
            <div className={cn('flex items-baseline gap-1.5 mb-0.5', isOwn && 'flex-row-reverse')}>
              <span className="text-[11px] font-semibold text-foreground">{userName}</span>
              {relativeDate && (
                <span className="text-[10px] text-muted-foreground/60">{relativeDate}</span>
              )}
            </div>

            {/* Message bubble */}
            {hasContent && (
              <div
                className={cn(
                  'rounded-2xl px-3 py-2 text-[13px] leading-relaxed',
                  isOwn
                    ? 'bg-primary text-primary-foreground rounded-tr-sm'
                    : 'bg-muted/60 text-foreground rounded-tl-sm',
                  isFirst && !isOwn && statusColor && 'border-l-2',
                )}
                style={isFirst && !isOwn && statusColor ? { borderLeftColor: statusColor } : undefined}
              >
                <p className="whitespace-pre-wrap">{content}</p>
              </div>
            )}

            {/* Attachments */}
            {hasMedia && (
              <div className={cn('mt-1 flex flex-wrap gap-1.5', isOwn && 'justify-end')}>
                {media.map((item) => (
                  <MediaAttachment key={item.id} media={item} compact />
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
  )
}

// --- Media Attachment ---

interface MediaAttachmentProps {
  media: IMedia
  compact?: boolean
}

function MediaAttachment({ media, compact = false }: MediaAttachmentProps) {
  const isImage = media.mime_type?.startsWith('image/')
  const handleDownload = () => {
    void downloadTicketMedia(media)
  }

  if (isImage) {
    return (
      <button
        type="button"
        onClick={handleDownload}
        className="block cursor-pointer overflow-hidden rounded-md border bg-transparent p-0 text-left"
      >
        <img
          src={media.preview_url || media.original_url}
          alt={media.name}
          className={cn('w-auto object-cover', compact ? 'h-16' : 'h-24')}
        />
      </button>
    )
  }

  return (
    <button
      type="button"
      onClick={handleDownload}
      className={cn(
        'flex cursor-pointer items-center gap-1.5 rounded-md border bg-transparent text-left transition-colors hover:bg-muted/50',
        compact ? 'px-2 py-1 text-xs' : 'px-3 py-2 text-sm',
        'text-muted-foreground',
      )}
    >
      <Paperclip className={cn('shrink-0', compact ? 'size-3' : 'size-3.5')} />
      <span className={cn('truncate', compact ? 'max-w-[120px]' : 'max-w-[200px]')}>
        {media.file_name}
      </span>
    </button>
  )
}

// --- File Preview (Discord-style) ---

interface FilePreviewProps {
  file: File
  onRemove: () => void
}

function FilePreview({ file, onRemove }: FilePreviewProps) {
  const isImage = file.type.startsWith('image/')
  const previewUrl = useMemo(() => {
    if (!isImage) {
      return null
    }

    return URL.createObjectURL(file)
  }, [file, isImage])

  useEffect(() => {
    return () => {
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl)
      }
    }
  }, [previewUrl])

  const sizeKB = Math.round(file.size / 1024)
  const sizeLabel = sizeKB > 1024 ? `${(sizeKB / 1024).toFixed(1)} MB` : `${sizeKB} KB`

  return (
    <div className="group relative flex items-center gap-2 rounded-lg border bg-background px-2 py-1.5 lg:px-3 lg:py-2 shadow-sm transition-shadow hover:shadow-md">
      <button
        type="button"
        onClick={onRemove}
        className="absolute -right-1.5 -top-1.5 flex size-5 items-center justify-center rounded-full border border-border/70 bg-background/95 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
        aria-label={`Quitar ${file.name}`}
      >
        <X className="size-3" />
      </button>

      {isImage && previewUrl ? (
        <img
          src={previewUrl}
          alt={file.name}
          className="size-8 lg:size-10 shrink-0 rounded object-cover"
        />
      ) : (
        <div className="flex size-8 lg:size-10 shrink-0 items-center justify-center rounded bg-muted">
          <FileIcon className="size-4 lg:size-5 text-muted-foreground" />
        </div>
      )}

      <div className="min-w-0">
        <p className="truncate text-xs font-medium max-w-[100px] lg:max-w-[150px]">{file.name}</p>
        <p className="text-[10px] text-muted-foreground">{sizeLabel}</p>
      </div>
    </div>
  )
}

// --- Skeleton ---

function TimelineSkeleton() {
  return (
    <div className="space-y-4 lg:space-y-6">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="flex gap-2 lg:gap-3">
          <Skeleton className="size-7 lg:size-8 shrink-0 rounded-full" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-3 lg:h-4 w-32 lg:w-48" />
            <Skeleton className="h-12 lg:h-16 w-full rounded-lg" />
          </div>
        </div>
      ))}
    </div>
  )
}

// --- Helpers ---

function formatRelativeDate(dateStr: string): string {
  try {
    const date = parseISO(dateStr)
    if (Number.isNaN(date.getTime())) return ''
    return formatDistanceToNow(date, { addSuffix: true, locale: es })
  } catch {
    return ''
  }
}
