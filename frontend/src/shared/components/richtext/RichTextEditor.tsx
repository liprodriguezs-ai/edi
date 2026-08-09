import { Color, FontSize, TextStyle } from '@tiptap/extension-text-style'
import { EditorContent, useEditor } from '@tiptap/react'
import { StarterKit } from '@tiptap/starter-kit'
import { Bold, Italic, Link2, List, ListOrdered, Palette, UnderlineIcon } from 'lucide-react'
import { useCallback, useEffect, useRef } from 'react'

// ── Constantes ────────────────────────────────────────────────────────────────

const FONT_SIZES = [
  { label: 'Pequeño', value: '12px' },
  { label: 'Normal', value: '14px' },
  { label: 'Grande', value: '18px' },
  { label: 'Muy grande', value: '24px' },
] as const

// ── Botón de toolbar ──────────────────────────────────────────────────────────

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

// ── Props ─────────────────────────────────────────────────────────────────────

interface RichTextEditorProps {
  /** HTML actual (bindea con RHF vía `<Controller>`). */
  value: string
  /** Emite el HTML editado; string vacío cuando el editor queda vacío (para validar). */
  onChange: (_html: string) => void
  disabled?: boolean
  /** Marca el borde en rojo cuando el campo es inválido. */
  invalid?: boolean
  /** id del área editable, para enlazar con un `<FieldLabel htmlFor>`. */
  id?: string
  /**
   * id del label visible que nombra el editor (a11y). Cuando se pasa, el área editable
   * (`role=textbox`) usa `aria-labelledby` en vez del `aria-label` genérico.
   */
  ariaLabelledBy?: string
}

// ── Componente ────────────────────────────────────────────────────────────────

/**
 * Editor de texto enriquecido (TipTap) reutilizable. Generaliza el compositor del
 * chat (`MessageComposer`) sin las piezas propias del chat (enter-para-enviar, pegar
 * imágenes). Toolbar: negrita, cursiva, subrayado, listas, enlace, color y tamaño.
 * Underline y Link ya vienen en StarterKit v3; TextStyle/Color/FontSize se agregan aparte.
 */
export function RichTextEditor({
  value,
  onChange,
  disabled,
  invalid,
  id,
  ariaLabelledBy,
}: RichTextEditorProps) {
  // Mantiene la referencia estable al onChange para el callback onUpdate del editor.
  const onChangeRef = useRef(onChange)
  useEffect(() => {
    onChangeRef.current = onChange
  }, [onChange])

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        link: {
          openOnClick: false,
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
      }),
      TextStyle,
      Color,
      FontSize,
    ],
    content: value || '',
    editable: !disabled,
    editorProps: {
      attributes: {
        class:
          'outline-none min-h-[160px] max-h-[380px] overflow-y-auto px-3 py-2.5 text-[14px] leading-relaxed text-foreground [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1 [&_a]:text-[var(--green-deep)] [&_a]:underline',
        // Nombra el textbox: si hay un label visible, lo referenciamos; si no, aria-label genérico.
        ...(ariaLabelledBy
          ? { 'aria-labelledby': ariaLabelledBy }
          : { 'aria-label': 'Contenido del aviso' }),
        'aria-multiline': 'true',
        role: 'textbox',
        ...(id ? { id } : {}),
      },
    },
    onUpdate: ({ editor: instance }) => {
      onChangeRef.current(instance.isEmpty ? '' : instance.getHTML())
    },
  })

  // Sincroniza el valor externo (reset de RHF en edición) hacia el editor.
  useEffect(() => {
    if (!editor) return
    const current = editor.isEmpty ? '' : editor.getHTML()
    if (value !== current) {
      editor.commands.setContent(value || '', { emitUpdate: false })
    }
  }, [editor, value])

  // Refleja el estado disabled en la editabilidad del editor.
  useEffect(() => {
    editor?.setEditable(!disabled)
  }, [editor, disabled])

  const handleColorChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    editor?.chain().focus().setColor(e.target.value).run()
  }

  const handleFontSizeChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    if (!editor) return
    const size = e.target.value
    if (size) {
      editor.chain().focus().setFontSize(size).run()
    } else {
      editor.chain().focus().unsetFontSize().run()
    }
  }

  // Enlace: pide la URL; vacío desvincula, cancelar no hace nada.
  const handleLink = useCallback(() => {
    if (!editor) return
    const previousUrl = (editor.getAttributes('link').href as string | undefined) ?? ''
    const url = window.prompt('URL del enlace', previousUrl || 'https://')
    if (url === null) return
    if (url.trim() === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
      return
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run()
  }, [editor])

  const colorInputRef = useRef<HTMLInputElement>(null)

  return (
    <div
      className={`overflow-hidden rounded-md border transition-colors focus-within:border-[var(--green)] ${
        invalid ? 'border-destructive' : 'border-[var(--line-2)]'
      } ${disabled ? 'pointer-events-none opacity-60' : ''}`}
    >
      {/* Toolbar de formato */}
      <div
        role='toolbar'
        aria-label='Herramientas de formato'
        className='flex flex-wrap items-center gap-0.5 border-b border-[var(--line-2)] px-2 py-1.5'
      >
        <ToolbarButton
          active={editor?.isActive('bold')}
          onClick={() => editor?.chain().focus().toggleBold().run()}
          disabled={disabled}
          title='Negrita'
        >
          <Bold className='size-4' />
        </ToolbarButton>
        <ToolbarButton
          active={editor?.isActive('italic')}
          onClick={() => editor?.chain().focus().toggleItalic().run()}
          disabled={disabled}
          title='Cursiva'
        >
          <Italic className='size-4' />
        </ToolbarButton>
        <ToolbarButton
          active={editor?.isActive('underline')}
          onClick={() => editor?.chain().focus().toggleUnderline().run()}
          disabled={disabled}
          title='Subrayado'
        >
          <UnderlineIcon className='size-4' />
        </ToolbarButton>

        <div className='mx-1 h-4 w-px bg-[var(--line-2)]' aria-hidden='true' />

        <ToolbarButton
          active={editor?.isActive('bulletList')}
          onClick={() => editor?.chain().focus().toggleBulletList().run()}
          disabled={disabled}
          title='Lista con viñetas'
        >
          <List className='size-4' />
        </ToolbarButton>
        <ToolbarButton
          active={editor?.isActive('orderedList')}
          onClick={() => editor?.chain().focus().toggleOrderedList().run()}
          disabled={disabled}
          title='Lista numerada'
        >
          <ListOrdered className='size-4' />
        </ToolbarButton>

        <div className='mx-1 h-4 w-px bg-[var(--line-2)]' aria-hidden='true' />

        <ToolbarButton
          active={editor?.isActive('link')}
          onClick={handleLink}
          disabled={disabled}
          title='Insertar enlace'
        >
          <Link2 className='size-4' />
        </ToolbarButton>

        {/* Color de texto */}
        <div className='relative'>
          <ToolbarButton
            onClick={() => colorInputRef.current?.click()}
            disabled={disabled}
            title='Color de texto'
          >
            <Palette className='size-4' />
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
          className='rounded border-0 bg-transparent py-0.5 pl-1 pr-4 text-[12px] text-[var(--ink-3)] outline-none hover:bg-[var(--surface-2)] disabled:pointer-events-none disabled:opacity-40'
          defaultValue=''
        >
          <option value=''>Tamaño</option>
          {FONT_SIZES.map(({ label, value: sizeValue }) => (
            <option key={sizeValue} value={sizeValue}>
              {label}
            </option>
          ))}
        </select>
      </div>

      <EditorContent editor={editor} />
    </div>
  )
}
