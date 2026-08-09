import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { docsUrlForPath, hasDedicatedDocs } from '@/shared/docs/docsRegistry'
import { CircleHelp } from 'lucide-react'
import { useLocation } from 'react-router-dom'

/**
 * Botón de ayuda de la barra superior. Lleva a la página del manual que
 * corresponde a la pantalla en la que está el usuario.
 *
 * El destino NO se pasa por props: se resuelve desde la ruta contra
 * `docsRegistry`. Así ninguna pantalla tiene que saber nada de documentación, y
 * mover una página del manual es editar UNA línea del registro.
 *
 * Abre en una pestaña nueva a propósito: la documentación es material de
 * consulta y perder el trabajo a medio capturar sería peor que el beneficio.
 */
export function DocsHelpButton() {
  const { pathname } = useLocation()
  const href = docsUrlForPath(pathname)

  // El texto dice a dónde va. Sin página propia, se anuncia como el manual
  // completo para no prometer una guía específica que no existe.
  const label = hasDedicatedDocs(pathname) ? 'Ayuda de esta pantalla' : 'Manual de EDI'

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <a
          href={href}
          target='_blank'
          rel='noopener noreferrer'
          aria-label={`${label} (se abre en una pestaña nueva)`}
          className='grid size-9 place-items-center rounded-full border border-border bg-card text-[var(--ink-2)] transition-colors hover:border-[var(--ink-4)] hover:text-foreground'
        >
          <CircleHelp className='size-4' aria-hidden />
        </a>
      </TooltipTrigger>
      <TooltipContent className='max-w-[260px]'>{label}</TooltipContent>
    </Tooltip>
  )
}
