import { ArrowLeft } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

interface PageHeaderBack {
  to: string
  label: string
}

interface PageHeaderProps {
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
  /** Back link shown above the title (detail pages). */
  back?: PageHeaderBack
  /** Extra line under the description (status pills, folio, dates). */
  meta?: ReactNode
}

export const PageHeader = ({ title, description, actions, back, meta }: PageHeaderProps) => (
  // max-sm:basis-full: dentro de las filas legacy `flex flex-wrap justify-between` de las
  // páginas, en mobile toma la fila completa y los botones de acción envuelven debajo en
  // vez de comprimir el título/descripción a una columna angosta.
  <div className='flex min-w-0 max-sm:basis-full flex-col gap-3 sm:flex-row sm:items-start sm:justify-between'>
    <div className='min-w-0 space-y-1'>
      {back && (
        <Link
          to={back.to}
          className='inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground'
        >
          <ArrowLeft className='size-4' aria-hidden />
          {back.label}
        </Link>
      )}
      <h1 className='text-xl font-bold tracking-tight sm:text-2xl'>{title}</h1>
      {description && <p className='text-sm text-muted-foreground sm:text-base'>{description}</p>}
      {meta && <div className='flex flex-wrap items-center gap-2 pt-1 text-sm text-muted-foreground'>{meta}</div>}
    </div>
    {actions && <div className='flex shrink-0 flex-wrap items-center gap-2'>{actions}</div>}
  </div>
)
