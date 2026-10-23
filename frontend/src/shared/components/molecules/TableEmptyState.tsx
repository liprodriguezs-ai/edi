import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Inbox, Plus, Search } from 'lucide-react'

interface TableEmptyStateProps {
  title?: string
  description?: string
  actionLabel?: string
  onAction?: () => void
  className?: string
  icon?: 'plus' | 'search' | 'default'
}

/**
 * Estado vacío de listas en canto vivo: panel blanco con hairline, sin gradientes,
 * glow, bordes punteados ni el color `energy` (fuera de la paleta). Ícono neutro,
 * jerarquía por tipografía. El acento índigo solo aparece en la acción.
 */
export const TableEmptyState = ({
  title = 'No se encontraron resultados',
  description = 'Intenta ajustar tus filtros o agregar un nuevo registro para comenzar.',
  actionLabel,
  onAction,
  className,
  icon = 'default',
}: TableEmptyStateProps) => {
  const iconClasses = 'h-8 w-8 text-muted-foreground shrink-0'

  const renderIcon = () => {
    switch (icon) {
      case 'plus':
        return <Plus className={iconClasses} strokeWidth={1.5} />
      case 'search':
        return <Search className={iconClasses} strokeWidth={1.5} />
      default:
        return <Inbox className={iconClasses} strokeWidth={1.5} />
    }
  }

  const hasAction = Boolean(actionLabel && onAction)

  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        'flex flex-col items-center justify-center gap-4 border border-border bg-card px-8 py-16 text-center',
        className
      )}
    >
      {renderIcon()}
      <div className="max-w-md space-y-1.5">
        <h3 className="text-sm font-semibold text-foreground">{title}</h3>
        {description && <p className="text-sm text-muted-foreground">{description}</p>}
      </div>
      {hasAction && (
        <Button onClick={onAction} className="mt-1">
          <Plus className="mr-2 h-4 w-4 shrink-0" aria-hidden />
          {actionLabel}
        </Button>
      )}
    </div>
  )
}
