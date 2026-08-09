import { Textarea } from '@/components/ui/textarea'
import type { IChatMessage } from '@/modules/chat/schema'
import { MediaAttachmentCard } from '@/shared/components/molecules/MediaAttachmentCard'
import type { IMedia } from '@/shared/interfaces/Entity'
import { displayInitials } from '@/shared/utils/displayInitials'
import DOMPurify, { type Config as DOMPurifyConfig } from 'dompurify'
import { EyeOff, Flag, MessageCircle, Pencil } from 'lucide-react'
import { memo, useMemo, useState } from 'react'

// ── DOMPurify config (refleja la allowlist del backend) ───────────────────────

const PURIFY_CONFIG: DOMPurifyConfig = {
  ALLOWED_TAGS: ['b', 'strong', 'i', 'em', 'u', 'p', 'br', 'ul', 'ol', 'li', 'span', 'a'],
  ALLOWED_ATTR: ['style', 'href', 'target', 'rel'],
  FORCE_BODY: true,
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Lee el título guardado en las custom_properties de Spatie Media Library. */
function getMediaTitle(media: IMedia): string | undefined {
  // IMedia.custom_properties está tipado como unknown[] pero Spatie serializa
  // un objeto JSON cuando hay custom_properties — lo casteamos vía unknown.
  const props = media.custom_properties as unknown as Record<string, string> | null | undefined
  return props?.title ?? undefined
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface ChatMessageBubbleProps {
  message: IChatMessage
  currentUserId?: string
  canModerate: boolean
  pending: boolean
  onReport: (_message: IChatMessage) => void
  onHide: (_message: IChatMessage) => void
  onUnhide: (_message: IChatMessage) => void
  onReact: (_message: IChatMessage, _hasReacted: boolean) => void
  onEdit: (_id: string, _body: string) => Promise<unknown>
  /** Abre un chat directo con el autor del mensaje (oculto en canales directos). */
  onDirectMessage?: (_message: IChatMessage) => void
}

// ── Subcomponentes ────────────────────────────────────────────────────────────

/** Mensaje ocultado por moderación, con opción de mostrarlo de nuevo. */
function HiddenMessage({
  canModerate,
  onUnhide,
  pending,
}: {
  canModerate: boolean
  onUnhide: () => void
  pending: boolean
}) {
  return (
    <p className='flex items-center justify-center gap-2 text-center text-[11.5px] italic text-[var(--ink-4)]'>
      Mensaje ocultado por moderación
      {canModerate && (
        <button
          type='button'
          onClick={onUnhide}
          className='not-italic text-[var(--green-deep)] hover:underline'
          disabled={pending}
        >
          Mostrar
        </button>
      )}
    </p>
  )
}

/** Barra de acciones bajo el mensaje: reacción 👍 + editar (propio) o DM/reportar/ocultar. */
function BubbleActions({
  message,
  currentUserId,
  isMine,
  canModerate,
  pending,
  onReact,
  onReport,
  onHide,
  onStartEdit,
  onDirectMessage,
}: {
  message: IChatMessage
  currentUserId?: string
  isMine: boolean
  canModerate: boolean
  pending: boolean
  onReact: (_message: IChatMessage, _hasReacted: boolean) => void
  onReport: (_message: IChatMessage) => void
  onHide: (_message: IChatMessage) => void
  onStartEdit: () => void
  onDirectMessage?: (_message: IChatMessage) => void
}) {
  const likes = message.reactions?.filter((r) => r.emoji === '👍') ?? []
  const likeCount = likes.length
  const reacted = likes.some((r) => r.user_id === currentUserId)

  return (
    <div className='mt-1 flex items-center gap-2.5'>
      <button
        type='button'
        onClick={() => onReact(message, reacted)}
        disabled={pending}
        className={`flex items-center gap-1 rounded-full px-1.5 text-[11.5px] transition-colors ${
          reacted
            ? 'font-semibold text-[var(--green-deep)]'
            : 'text-[var(--ink-4)] hover:text-[var(--ink-2)]'
        }`}
        aria-label='Reaccionar con me gusta'
      >
        <span>👍</span>
        {likeCount > 0 ? <span>{likeCount}</span> : null}
      </button>
      <div className='hidden items-center gap-2.5 group-hover:flex'>
        {isMine ? (
          <button
            type='button'
            onClick={onStartEdit}
            className='flex items-center gap-1 text-[11px] text-[var(--ink-4)] hover:text-[var(--ink-2)]'
          >
            <Pencil className='size-3' />
            Editar
          </button>
        ) : (
          <>
            {onDirectMessage && message.user_id && (
              <button
                type='button'
                onClick={() => onDirectMessage(message)}
                className='flex items-center gap-1 text-[11px] text-[var(--ink-4)] hover:text-[var(--green-deep)]'
              >
                <MessageCircle className='size-3' />
                Mensaje
              </button>
            )}
            <button
              type='button'
              onClick={() => onReport(message)}
              className='flex items-center gap-1 text-[11px] text-[var(--ink-4)] hover:text-destructive'
            >
              <Flag className='size-3' />
              Reportar
            </button>
            {canModerate && (
              <button
                type='button'
                onClick={() => onHide(message)}
                className='flex items-center gap-1 text-[11px] text-[var(--ink-4)] hover:text-destructive'
              >
                <EyeOff className='size-3' />
                Ocultar
              </button>
            )}
          </>
        )}
      </div>
    </div>
  )
}

/** Galería de adjuntos de imagen (Spatie Media Library). */
function AttachedImages({ media }: { media: IMedia[] }) {
  const images = media.filter((m) => m.mime_type.startsWith('image/'))
  if (images.length === 0) return null

  return (
    <div className='mt-2 flex flex-wrap gap-1.5'>
      {images.map((m) => {
        const title = getMediaTitle(m)
        const src = m.preview_url || m.original_url
        return (
          <a
            key={m.id}
            href={m.original_url}
            target='_blank'
            rel='noopener noreferrer'
            className='block overflow-hidden rounded-md border border-[var(--line-2)]'
            aria-label={title ?? `Imagen adjunta ${m.name}`}
          >
            <img
              src={src}
              alt={title ?? m.name}
              className='size-24 object-cover transition-opacity hover:opacity-90'
              loading='lazy'
            />
            {title && (
              <p className='px-1.5 py-0.5 text-[10.5px] text-[var(--ink-3)] leading-tight'>
                {title}
              </p>
            )}
          </a>
        )
      })}
    </div>
  )
}

/** Adjuntos no-imagen (PDF, Office, audio/video): tarjeta de descarga compartida. */
function AttachedFiles({ media }: { media: IMedia[] }) {
  const files = media.filter((m) => !m.mime_type.startsWith('image/'))
  if (files.length === 0) return null

  return (
    <div className='mt-2 flex flex-col gap-1.5'>
      {files.map((m) => (
        <MediaAttachmentCard key={m.id} media={m} />
      ))}
    </div>
  )
}

/** Cuerpo del mensaje: HTML enriquecido (si body_html presente) o texto plano. */
function MessageBody({ message, edited }: { message: IChatMessage; edited: boolean }) {
  // Solo renderiza HTML enriquecido cuando body_html está presente y no nulo
  const sanitizedHtml = useMemo(() => {
    if (!message.body_html) return null
    return DOMPurify.sanitize(message.body_html, PURIFY_CONFIG)
  }, [message.body_html])

  const media = message.media ?? []

  return (
    <>
      {sanitizedHtml ? (
        <div
          dangerouslySetInnerHTML={{ __html: sanitizedHtml }}
          className='prose-sm prose-p:my-0.5 prose-ul:my-0.5 prose-ol:my-0.5 text-[13.5px] text-foreground [&_a]:text-[var(--green-deep)] [&_a]:underline'
        />
      ) : (
        <p className='whitespace-pre-wrap text-[13.5px] text-foreground'>{message.body}</p>
      )}
      {media.length > 0 && <AttachedImages media={media} />}
      {media.length > 0 && <AttachedFiles media={media} />}
      {edited && <span className='ml-1.5 text-[10.5px] text-[var(--ink-4)]'>(editado)</span>}
    </>
  )
}

// ── Componente principal ──────────────────────────────────────────────────────

/** Burbuja de un mensaje del chat: cuerpo, edición inline (propio) y acciones. */
export const ChatMessageBubble = memo(function ChatMessageBubble({
  message,
  currentUserId,
  canModerate,
  pending,
  onReport,
  onHide,
  onUnhide,
  onReact,
  onEdit,
  onDirectMessage,
}: ChatMessageBubbleProps) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState('')

  if (message.is_hidden) {
    return (
      <HiddenMessage
        canModerate={canModerate}
        onUnhide={() => onUnhide(message)}
        pending={pending}
      />
    )
  }

  const isMine = message.user_id === currentUserId

  const startEdit = () => {
    setDraft(message.body ?? '')
    setEditing(true)
  }

  const save = async () => {
    const body = draft.trim()
    if (!body) return
    await onEdit(message.id, body)
    setEditing(false)
  }

  return (
    <div className={`flex items-start gap-2.5 ${isMine ? 'flex-row-reverse' : ''}`}>
      <span className='grid size-8 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[11px] font-semibold text-[var(--green)]'>
        {displayInitials(message.user?.name ?? '?')}
      </span>
      <div
        className={`group max-w-[70%] rounded-[14px] px-3.5 py-2.5 ${isMine ? 'bg-[var(--green-bg)]' : 'bg-[var(--surface-2)]'}`}
      >
        <p className='text-[11.5px] font-semibold text-[var(--green-deep)]'>
          {message.user?.name ?? 'Residente'}
        </p>

        {editing ? (
          <div className='mt-1 space-y-1.5'>
            <Textarea
              rows={2}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              className='min-h-[44px] resize-none bg-card text-[13.5px]'
              aria-label='Editar mensaje'
            />
            <div className='flex justify-end gap-3'>
              <button
                type='button'
                onClick={() => setEditing(false)}
                className='text-[11.5px] text-[var(--ink-3)] hover:underline'
              >
                Cancelar
              </button>
              <button
                type='button'
                onClick={save}
                disabled={pending || draft.trim().length === 0}
                className='text-[11.5px] font-semibold text-[var(--green-deep)] hover:underline disabled:opacity-50'
              >
                Guardar
              </button>
            </div>
          </div>
        ) : (
          <MessageBody message={message} edited={Boolean(message.edited_at)} />
        )}

        {!editing && (
          <BubbleActions
            message={message}
            currentUserId={currentUserId}
            isMine={isMine}
            canModerate={canModerate}
            pending={pending}
            onReact={onReact}
            onReport={onReport}
            onHide={onHide}
            onStartEdit={startEdit}
            onDirectMessage={onDirectMessage}
          />
        )}
      </div>
    </div>
  )
})
