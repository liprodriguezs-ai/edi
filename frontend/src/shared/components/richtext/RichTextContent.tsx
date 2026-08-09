import DOMPurify, { type Config as DOMPurifyConfig } from 'dompurify'
import { useMemo } from 'react'

// ── DOMPurify config ──────────────────────────────────────────────────────────
// Misma allowlist que el chat (`ChatMessageBubble`), que a su vez refleja la
// lista blanca del sanitizador del backend. El HTML del cliente NUNCA se confía:
// el servidor lo sanitiza al guardar y acá lo re-sanitizamos al renderizar.
const PURIFY_CONFIG: DOMPurifyConfig = {
  ALLOWED_TAGS: ['b', 'strong', 'i', 'em', 'u', 'p', 'br', 'ul', 'ol', 'li', 'span', 'a'],
  ALLOWED_ATTR: ['style', 'href', 'target', 'rel'],
  FORCE_BODY: true,
}

// Estilos de tipografía por elemento. No hay `@tailwindcss/typography` instalado,
// así que aplicamos variantes de arbitrary selectors puntuales.
const TYPOGRAPHY_CLASSES =
  '[&_p]:my-1 [&_ul]:my-1 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-0.5 [&_a]:underline [&_a]:text-[var(--green-deep)] [&_strong]:font-semibold'

interface RichTextContentProps {
  /** HTML enriquecido (ya sanitizado en el servidor; se re-sanitiza acá por seguridad). */
  html?: string | null
  /** Texto plano de respaldo cuando no hay HTML (avisos previos al rich text). */
  fallbackText?: string | null
  className?: string
}

/**
 * Renderiza el contenido enriquecido de un aviso/mensaje: sanitiza el HTML con la
 * misma allowlist del chat y lo pinta con `dangerouslySetInnerHTML`. Si no hay HTML,
 * cae al texto plano (`fallbackText`) preservando saltos de línea.
 */
export function RichTextContent({ html, fallbackText, className }: RichTextContentProps) {
  const sanitizedHtml = useMemo(() => {
    if (!html) return null
    const clean = DOMPurify.sanitize(html, PURIFY_CONFIG)
    // Un HTML que sólo tiene etiquetas vacías (p. ej. `<p></p>`) no aporta nada →
    // lo tratamos como ausente para caer al texto plano.
    return clean.replace(/<[^>]*>/g, '').trim().length > 0 ? clean : null
  }, [html])

  if (sanitizedHtml) {
    return (
      <div
        dangerouslySetInnerHTML={{ __html: sanitizedHtml }}
        className={`${TYPOGRAPHY_CLASSES} ${className ?? ''}`.trim()}
      />
    )
  }

  return <p className={`whitespace-pre-wrap ${className ?? ''}`.trim()}>{fallbackText ?? ''}</p>
}
