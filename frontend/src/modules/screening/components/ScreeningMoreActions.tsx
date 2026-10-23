import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { MoreHorizontal } from 'lucide-react'

interface ScreeningMoreActionsProps {
  statusKey: string | undefined
  onVerify: () => void
  onAnnul: () => void
}

/**
 * Occasional actions, out of the main flow. Each one says what it is for and
 * appears only in the states the server accepts it.
 */
export function ScreeningMoreActions({ statusKey, onVerify, onAnnul }: ScreeningMoreActionsProps) {
  const canRescore = usePermissions('rescore.screening')
  const canAnnul = usePermissions('delete.screening')

  const showVerify = canRescore && (statusKey === 'scored' || statusKey === 'completed')
  const showAnnul = canAnnul && statusKey !== undefined && statusKey !== 'invalidated'

  if (!showVerify && !showAnnul) return null

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant='outline' aria-label='Más acciones'>
          <MoreHorizontal className='size-4' aria-hidden />
          <span className='hidden sm:inline'>Más acciones</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align='end' className='w-72'>
        {showVerify && (
          <DropdownMenuItem onSelect={onVerify} className='flex-col items-start gap-0.5'>
            <span className='font-medium'>Verificar cálculo</span>
            <span className='text-xs text-muted-foreground'>
              Auditoría: repite el cálculo o compara con otra versión del instrumento.
            </span>
          </DropdownMenuItem>
        )}
        {showAnnul && (
          <DropdownMenuItem onSelect={onAnnul} className='flex-col items-start gap-0.5'>
            <span className='font-medium text-destructive'>Anular prueba</span>
            <span className='text-xs text-muted-foreground'>
              Para una prueba capturada por error (por ejemplo, otro niño).
            </span>
          </DropdownMenuItem>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
