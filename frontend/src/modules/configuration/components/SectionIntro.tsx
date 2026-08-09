import type { LucideIcon } from 'lucide-react'

interface SectionIntroProps {
  title: string
  description: string
  icon?: LucideIcon
}

/**
 * Bloque de encabezado descriptivo para cada tab de la página de Configuración.
 * Usa un <h2> semántico para que los lectores de pantalla puedan navegar por sección.
 * Visualmente ligero — contraste bajo para no competir con el contenido del formulario.
 */
export function SectionIntro({ title, description, icon: Icon }: SectionIntroProps) {
  return (
    <div className="mb-4 flex items-start gap-2.5">
      {Icon && (
        <Icon
          className="mt-0.5 size-4 flex-none text-[var(--ink-3)]"
          aria-hidden
        />
      )}
      <div>
        <h2 className="text-[13.5px] font-semibold text-[var(--ink-1)]">{title}</h2>
        <p className="mt-0.5 text-[12.5px] leading-relaxed text-[var(--ink-3)]">{description}</p>
      </div>
    </div>
  )
}
