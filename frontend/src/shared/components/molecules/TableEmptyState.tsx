import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Inbox, Plus, Search } from 'lucide-react'

interface TableEmptyStateProps {
  title?: string;
  description?: string;
  actionLabel?: string;
  onAction?: () => void;
  className?: string;
  icon?: 'plus' | 'search' | 'default';
}

export const TableEmptyState = ({
  title = 'No se encontraron resultados',
  description = 'Intenta ajustar tus filtros o agregar un nuevo registro para comenzar.',
  actionLabel,
  onAction,
  className,
  icon = 'default'
}: TableEmptyStateProps) => {
  const iconClasses = 'h-10 w-10 text-energy/80 shrink-0';

  const renderIcon = () => {
    switch (icon) {
      case 'plus':
        return <Plus className={iconClasses} />;
      case 'search':
        return <Search className={iconClasses} />;
      default:
        return <Inbox className={iconClasses} strokeWidth={1.5} />;
    }
  };

  const hasAction = Boolean(actionLabel && onAction);

  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        'flex flex-col items-center justify-center gap-6 rounded-xl border-2 border-dashed border-energy/20 bg-linear-to-br from-background via-card to-background px-8 py-14 sm:py-16 text-center relative overflow-hidden transition-colors duration-200',
        className
      )}
    >
      <div className="absolute inset-0 bg-linear-to-br from-energy/5 via-transparent to-energy/5 opacity-50 pointer-events-none" aria-hidden />

      <div className="relative z-10 flex flex-col items-center gap-5 max-w-sm">
        <div
          className={cn(
            'flex items-center justify-center w-16 h-16 sm:w-20 sm:h-20 rounded-full bg-linear-to-br from-energy/20 to-energy/10 border border-energy/25 shadow-sm',
            hasAction && 'ring-2 ring-energy/10 ring-offset-2 ring-offset-background'
          )}
        >
          {renderIcon()}
        </div>

        <div className="space-y-2">
          <h3 className="font-semibold text-lg sm:text-xl text-foreground tracking-tight">
            {title}
          </h3>
          {description && (
            <p className="text-muted-foreground text-sm sm:text-base max-w-md leading-relaxed">
              {description}
            </p>
          )}
        </div>

        {hasAction && (
          <Button
            size="lg"
            onClick={onAction}
            className="mt-1 shadow-sm hover:shadow-md focus-visible:ring-2 focus-visible:ring-energy/40 transition-shadow duration-200"
          >
            <Plus className="h-4 w-4 sm:h-5 sm:w-5 mr-2 shrink-0" aria-hidden />
            {actionLabel}
          </Button>
        )}
      </div>
    </div>
  );
};
