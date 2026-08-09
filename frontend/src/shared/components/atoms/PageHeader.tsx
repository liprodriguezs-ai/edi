import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  description: string
  actions?: ReactNode
}

export const PageHeader = ({ title, description, actions }: PageHeaderProps) => (
  // max-sm:basis-full: dentro de las filas legacy `flex flex-wrap justify-between` de las
  // páginas, en mobile toma la fila completa y los botones de acción envuelven debajo en
  // vez de comprimir el título/descripción a una columna angosta.
  <div className='flex min-w-0 max-sm:basis-full flex-col gap-3 sm:flex-row sm:items-start sm:justify-between'>
    <div className='min-w-0'>
      <h1 className='text-xl font-bold tracking-tight sm:text-2xl'>{title}</h1>
      <p className='text-sm text-muted-foreground sm:text-base'>{description}</p>
    </div>
    {actions && <div className='flex shrink-0 flex-wrap items-center gap-2'>{actions}</div>}
  </div>
)
