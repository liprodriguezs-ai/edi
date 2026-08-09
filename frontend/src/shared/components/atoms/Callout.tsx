import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

interface CalloutProps {
  icon: LucideIcon
  children: ReactNode
  className?: string
}

/**
 * Aviso informativo en línea con el estilo verde de la marca (mismo lenguaje
 * visual que el banner "Vista previa"). Para notas/contexto dentro de
 * formularios y pantallas. El icono se pasa como componente de lucide-react.
 */
export function Callout({ icon: Icon, children, className = '' }: CalloutProps) {
  return (
    <div
      className={`flex items-start gap-2 rounded-[10px] bg-[var(--green-bg)] px-3.5 py-2.5 text-[12.5px] leading-relaxed text-[var(--green-deep)] ${className}`}
    >
      <Icon className='mt-0.5 size-4 flex-none' aria-hidden />
      <span>{children}</span>
    </div>
  )
}
