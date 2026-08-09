import { Button } from '@/components/ui/button'
import {
  compressImageToWebP,
  isAllowedAttachment,
  isValidAttachmentSize,
} from '@/modules/chat/helpers/imageCompressor'
import type { SendMessagePayload } from '@/modules/chat/hooks/useSendMessage'
import { Color, FontSize, TextStyle } from '@tiptap/extension-text-style'
import { Underline } from '@tiptap/extension-underline'
import { EditorContent, useEditor } from '@tiptap/react'
import { StarterKit } from '@tiptap/starter-kit'
import {
  Bold,
  FileText,
  ImagePlus,
  Italic,
  List,
  ListOrdered,
  Lock,
  Palette,
  SendHorizonal,
  UnderlineIcon,
  X,
} from 'lucide-react'
import { useCallback, useRef, useState } from 'react'
import { toast } from '@/shared/hooks/useToast'

// ── Tipos ─────────────────────────────────────────────────────────────────────

interface FileAttachment {
  /** Archivo a enviar (comprimido a WebP si es imagen; sin cambios si no lo es). */
  file: File
  /** Título opcional para el adjunto. */
  title: string
  /** Tipo de adjunto: imagen (con miniatura) o archivo genérico (con chip). */
  kind: 'image' | 'file'
  /** URL de objeto para mostrar la miniatura — solo presente cuando kind === 'image'. */
  previewUrl?: string
}

interface MessageComposerProps {
  channelId: string | null
  canWrite: boolean
  pending: boolean
  /** Resolves when the send succeeds, rejects when it fails — drives clear-on-success. */
  onSend: (_payload: SendMessagePayload) => Promise<unknown>
}

// ── Constantes ────────────────────────────────────────────────────────────────

/** Máximo de adjuntos por mensaje y tamaño máximo por archivo (MB) — espejo de MediaAttachmentPolicy. */
const MAX_FILES = 5
const MAX_MB = 10

/** `accept` del input de archivo — mismo whitelist que MediaAttachmentPolicy del backend. */
const ACCEPT_ATTACHMENTS =
  '.pdf,.jpg,.jpeg,.png,.webp,.gif,.mp4,.mp3,.doc,.docx,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4,audio/mpeg,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

const FONT_SIZES = [
  { label: 'Pequeño', value: '12px' },
  { label: 'Normal', value: '14px' },
  { label: 'Grande', value: '18px' },
  { label: 'Muy grande', value: '24px' },
] as const

// ── Componente auxiliar: botón de toolbar ─────────────────────────────────────

function ToolbarButton({
  active,
  onClick,
  disabled,
  title,
  children,
}: {
  active?: boolean
  onClick: () => void
  disabled?: boolean
  title: string
  children: React.ReactNode
}) {
  return (
    <button
      type='button'
      onMouseDown={(e) => {
        e.preventDefault() // evita que el editor pierda el foco
        onClick()
      }}
      disabled={disabled}
      title={title}
      aria-label={title}
      aria-pressed={active}
      className={[
        'flex items-center justify-center rounded p-1 transition-colors',
        active
          ? 'bg-[var(--green-bg)] text-[var(--green-deep)]'
          : 'text-[var(--ink-3)] hover:bg-[var(--surface-2)] hover:text-[var(--ink-1)]',
        disabled ? 'pointer-events-none opacity-40' : '',
      ].join(' ')}
    >
      {children}
    </button>
  )
}

// ── Componente principal ──────────────────────────────────────────────────────

/**
 * Compositor de mensajes enriquecidos (TipTap).
 *
 * - Toolbar: negrita, cursiva, subrayado, listas, color de texto, tamaño.
 * - Adjuntar imágenes: botón de archivo + pegar desde portapapeles.
 * - Imágenes comprimidas a WebP (canvas, sin dependencias externas).
 * - Enter envía; Shift+Enter inserta salto de línea.
 * - Read-only cuando !canWrite.
 */
export function MessageComposer({ channelId, canWrite, pending, onSend }: MessageComposerProps) {
  const [attachments, setAttachments] = useState<FileAttachment[]>([])
  const fileInputRef = useRef<HTMLInputElement>(null)
  const colorInputRef = useRef<HTMLInputElement>(null)

  // ── Editor ────────────────────────────────────────────────────────────────

  const editor = useEditor({
    extensions: [StarterKit, TextStyle, Color, FontSize, Underline],
    editorProps: {
      attributes: {
        class:
          'outline-none min-h-[40px] max-h-[160px] overflow-y-auto text-[13.5px] text-foreground',
        'aria-label': 'Mensaje',
        'aria-multiline': 'true',
        role: 'textbox',
      },
      handleKeyDown: (_view, event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault()
          handleSend()
          return true
        }
        return false
      },
      handlePaste: (_view, event) => {
        // Clipboard file items are images in practice — paste stays image-only.
        const items = Array.from(event.clipboardData?.items ?? [])
        const imageItems = items.filter(
          (item) => item.kind === 'file' && item.type.startsWith('image/')
        )
        if (imageItems.length === 0) return false

        event.preventDefault()
        imageItems.forEach((item) => {
          const file = item.getAsFile()
          if (file) void addAttachment(file)
        })
        return true
      },
    },
  })

  // ── Adjunto: procesar y agregar ───────────────────────────────────────────

  const addAttachment = useCallback(
    async (rawFile: File) => {
      if (attachments.length >= MAX_FILES) {
        toast.error(`Máximo ${MAX_FILES} adjuntos por mensaje`)
        return
      }
      if (!isAllowedAttachment(rawFile)) {
        toast.error('Tipo de archivo no permitido')
        return
      }
      if (!isValidAttachmentSize(rawFile, MAX_MB)) {
        toast.error(`El archivo supera el límite de ${MAX_MB} MB`)
        return
      }

      const isImage = rawFile.type.startsWith('image/')

      if (!isImage) {
        setAttachments((prev) => [...prev, { file: rawFile, title: '', kind: 'file' }])
        return
      }

      try {
        const compressed = await compressImageToWebP(rawFile)
        const previewUrl = URL.createObjectURL(compressed)
        setAttachments((prev) => [
          ...prev,
          { file: compressed, title: '', kind: 'image', previewUrl },
        ])
      } catch {
        toast.error('No se pudo comprimir la imagen')
      }
    },
    [attachments.length]
  )

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    Array.from(e.target.files ?? []).forEach((f) => void addAttachment(f))
    e.target.value = ''
  }

  const removeAttachment = (index: number) => {
    setAttachments((prev) => {
      const previewUrl = prev[index].previewUrl
      if (previewUrl) URL.revokeObjectURL(previewUrl)
      return prev.filter((_, i) => i !== index)
    })
  }

  const updateTitle = (index: number, title: string) => {
    setAttachments((prev) => prev.map((item, i) => (i === index ? { ...item, title } : item)))
  }

  // ── Envío ─────────────────────────────────────────────────────────────────

  const handleSend = useCallback(() => {
    if (!editor || !channelId || pending) return

    const html = editor.getHTML()
    const isEditorEmpty = editor.isEmpty
    const hasAttachments = attachments.length > 0

    if (isEditorEmpty && !hasAttachments) return

    const payload: SendMessagePayload = {
      body_html: isEditorEmpty ? undefined : html,
      attachments: hasAttachments ? attachments.map((a) => a.file) : undefined,
      attachment_titles: hasAttachments ? attachments.map((a) => a.title) : undefined,
    }

    // Clear the editor and attachments ONLY after the send SUCCEEDS. Clearing
    // optimistically discarded the drafted message and revoked the preview blobs even
    // when the send failed, leaving no way to retry without rebuilding everything.
    const sentAttachments = attachments
    void onSend(payload)
      .then(() => {
        editor.commands.clearContent()
        sentAttachments.forEach((a) => {
          if (a.previewUrl) URL.revokeObjectURL(a.previewUrl)
        })
        setAttachments([])
      })
      .catch(() => {
        // Send failed (useSendMessage.onError already shows a toast) — keep the draft
        // and attachments intact so the user can retry without recomposing.
      })
  }, [editor, channelId, pending, attachments, onSend])

  // ── Toolbar: color ────────────────────────────────────────────────────────

  const handleColorChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!editor) return
    editor.chain().focus().setColor(e.target.value).run()
  }

  // ── Toolbar: tamaño ───────────────────────────────────────────────────────

  const handleFontSizeChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    if (!editor) return
    const value = e.target.value
    if (value) {
      editor.chain().focus().setFontSize(value).run()
    } else {
      editor.chain().focus().unsetFontSize().run()
    }
  }

  // ── Solo lectura ──────────────────────────────────────────────────────────

  if (channelId && !canWrite) {
    return (
      <div className='flex items-center justify-center gap-2 border-t border-[var(--line-2)] px-4 py-3 text-[12.5px] text-[var(--ink-3)]'>
        <Lock className='size-3.5 flex-none' />
        <span>Solo lectura — solo la mesa directiva puede escribir aquí.</span>
      </div>
    )
  }

  const disabled = !channelId || pending
  const canSend = !disabled && (!editor?.isEmpty || attachments.length > 0)

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className='border-t border-[var(--line-2)]'>
      {/* Toolbar de formato */}
      <div
        className='flex flex-wrap items-center gap-0.5 border-b border-[var(--line-2)] px-3 py-1.5'
        aria-label='Herramientas de formato'
      >
        <ToolbarButton
          active={editor?.isActive('bold')}
          onClick={() => editor?.chain().focus().toggleBold().run()}
          disabled={disabled}
          title='Negrita'
        >
          <Bold className='size-3.5' />
        </ToolbarButton>

        <ToolbarButton
          active={editor?.isActive('italic')}
          onClick={() => editor?.chain().focus().toggleItalic().run()}
          disabled={disabled}
          title='Cursiva'
        >
          <Italic className='size-3.5' />
        </ToolbarButton>

        <ToolbarButton
          active={editor?.isActive('underline')}
          onClick={() => editor?.chain().focus().toggleUnderline().run()}
          disabled={disabled}
          title='Subrayado'
        >
          <UnderlineIcon className='size-3.5' />
        </ToolbarButton>

        <div className='mx-1 h-4 w-px bg-[var(--line-2)]' aria-hidden='true' />

        <ToolbarButton
          active={editor?.isActive('bulletList')}
          onClick={() => editor?.chain().focus().toggleBulletList().run()}
          disabled={disabled}
          title='Lista con viñetas'
        >
          <List className='size-3.5' />
        </ToolbarButton>

        <ToolbarButton
          active={editor?.isActive('orderedList')}
          onClick={() => editor?.chain().focus().toggleOrderedList().run()}
          disabled={disabled}
          title='Lista numerada'
        >
          <ListOrdered className='size-3.5' />
        </ToolbarButton>

        <div className='mx-1 h-4 w-px bg-[var(--line-2)]' aria-hidden='true' />

        {/* Color de texto */}
        <div className='relative'>
          <ToolbarButton
            onClick={() => colorInputRef.current?.click()}
            disabled={disabled}
            title='Color de texto'
          >
            <Palette className='size-3.5' />
          </ToolbarButton>
          <input
            ref={colorInputRef}
            type='color'
            defaultValue='#000000'
            onChange={handleColorChange}
            className='absolute left-0 top-0 size-0 opacity-0'
            aria-label='Seleccionar color de texto'
            tabIndex={-1}
          />
        </div>

        {/* Tamaño de fuente */}
        <select
          onChange={handleFontSizeChange}
          disabled={disabled}
          aria-label='Tamaño de fuente'
          title='Tamaño de fuente'
          className='rounded border-0 bg-transparent py-0.5 pl-1 pr-4 text-[11.5px] text-[var(--ink-3)] outline-none hover:bg-[var(--surface-2)] disabled:pointer-events-none disabled:opacity-40'
          defaultValue=''
        >
          <option value=''>Tamaño</option>
          {FONT_SIZES.map(({ label, value }) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </div>

      {/* Miniaturas/chips de adjuntos */}
      {attachments.length > 0 && (
        <div className='flex flex-wrap gap-2 border-b border-[var(--line-2)] px-3 py-2'>
          {attachments.map((item, i) => (
            <div key={`${item.file.name}-${i}`} className='flex flex-col gap-1'>
              {item.kind === 'image' ? (
                <div className='relative size-16 overflow-hidden rounded-md border border-[var(--line-2)]'>
                  <img
                    src={item.previewUrl}
                    alt={item.title || `Imagen ${i + 1}`}
                    className='size-full object-cover'
                  />
                  <button
                    type='button'
                    onClick={() => removeAttachment(i)}
                    className='absolute right-0.5 top-0.5 flex size-4 items-center justify-center rounded-full bg-black/60 text-white hover:bg-black/80'
                    aria-label={`Quitar imagen ${i + 1}`}
                  >
                    <X className='size-2.5' />
                  </button>
                </div>
              ) : (
                <div className='relative flex h-16 w-32 flex-col items-center justify-center gap-1 rounded-md border border-[var(--line-2)] bg-[var(--surface-2)] px-2'>
                  <FileText className='size-4 text-[var(--ink-3)]' />
                  <span className='w-full truncate text-center text-[10.5px] text-[var(--ink-3)]'>
                    {item.file.name}
                  </span>
                  <button
                    type='button'
                    onClick={() => removeAttachment(i)}
                    className='absolute right-0.5 top-0.5 flex size-4 items-center justify-center rounded-full bg-black/60 text-white hover:bg-black/80'
                    aria-label={`Quitar archivo ${i + 1}`}
                  >
                    <X className='size-2.5' />
                  </button>
                </div>
              )}
              <input
                type='text'
                value={item.title}
                onChange={(e) => updateTitle(i, e.target.value)}
                placeholder='Título (opcional)'
                maxLength={120}
                className='w-16 rounded border border-[var(--line-2)] px-1 py-0.5 text-[10.5px] outline-none focus:border-[var(--green)]'
                aria-label={`Título de adjunto ${i + 1}`}
              />
            </div>
          ))}
        </div>
      )}

      {/* Editor + botones de acción */}
      <div className='flex items-end gap-2 px-4 py-3'>
        {/* Adjuntar archivo */}
        <button
          type='button'
          onClick={() => fileInputRef.current?.click()}
          disabled={disabled || attachments.length >= MAX_FILES}
          title='Adjuntar archivo'
          aria-label='Adjuntar archivo'
          className='flex-none text-[var(--ink-4)] hover:text-[var(--ink-2)] disabled:pointer-events-none disabled:opacity-40'
        >
          <ImagePlus className='size-4' />
        </button>
        <input
          ref={fileInputRef}
          type='file'
          accept={ACCEPT_ATTACHMENTS}
          multiple
          className='hidden'
          aria-hidden='true'
          tabIndex={-1}
          onChange={handleFileSelect}
        />

        {/* TipTap editor */}
        <div
          className={`flex-1 rounded-md border px-3 py-2 transition-colors ${disabled ? 'pointer-events-none opacity-60 border-[var(--line-2)]' : 'border-[var(--line-2)] focus-within:border-[var(--green)]'}`}
        >
          <EditorContent editor={editor} />
          {!channelId && <p className='text-[12.5px] text-[var(--ink-4)]'>Selecciona un canal</p>}
        </div>

        {/* Enviar */}
        <Button
          size='icon'
          className='size-10 flex-none rounded-full'
          onClick={handleSend}
          disabled={!canSend}
          aria-label='Enviar mensaje'
        >
          <SendHorizonal className='size-4' />
        </Button>
      </div>
    </div>
  )
}
