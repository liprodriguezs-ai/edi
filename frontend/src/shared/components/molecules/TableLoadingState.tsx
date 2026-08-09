import { cn } from '@/lib/utils'
import { Skeleton } from 'boneyard-js/react'
import { Loader2 } from 'lucide-react'

interface TableLoadingStateProps {
  message?: string
  rows?: number
  className?: string
  showSkeletons?: boolean
  mini?: boolean
}

export const TableLoadingState = ({
  message = 'Cargando datos...',
  rows = 3,
  className,
  showSkeletons = false,
  mini = false,
}: TableLoadingStateProps) => {
  if (showSkeletons) {
    return (
      <Skeleton name='table-loading-state' loading>
        <div className={cn('space-y-3', className)}>
          {Array.from({ length: rows }).map((_, index) => (
            <div
              key={index}
              className='flex items-center space-x-4 rounded-lg border border-border/50 bg-card/30 p-4'
            >
              <div className='w-10 h-10 bg-muted/30 rounded-full' />
              <div className='flex-1 space-y-2'>
                <div className='h-4 bg-muted/30 rounded w-3/4' />
                <div className='h-3 bg-muted/20 rounded w-1/2' />
              </div>
              <div className='w-20 h-8 bg-muted/30 rounded' />
            </div>
          ))}
        </div>
      </Skeleton>
    )
  }

  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-6 rounded-xl border-2 border-dashed border-energy/20 bg-gradient-to-br from-background via-card to-background px-8 text-center relative overflow-hidden',
        className,
        mini ? 'py-4' : 'py-16'
      )}
    >
      {/* Background glow effect */}
      <div className='absolute inset-0 bg-gradient-to-br from-energy/5 via-transparent to-energy/5 opacity-50' />

      <div className='relative z-10 flex flex-col items-center gap-6'>
        <div className='flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-energy/20 to-energy/10 border border-energy/30 shadow-lg'>
          <Loader2 className='h-10 w-10 text-energy animate-spin' />
        </div>

        <div className='space-y-3'>
          <h3 className='font-bold text-xl text-foreground'>{message}</h3>
          <p className='text-muted-foreground'>Por favor espera mientras se cargan los datos...</p>
        </div>

        {/* Loading dots animation */}
        <div className='flex space-x-2 mt-2'>
          <div
            className='w-3 h-3 bg-energy rounded-full animate-bounce'
            style={{ animationDelay: '0ms' }}
          />
          <div
            className='w-3 h-3 bg-energy rounded-full animate-bounce'
            style={{ animationDelay: '150ms' }}
          />
          <div
            className='w-3 h-3 bg-energy rounded-full animate-bounce'
            style={{ animationDelay: '300ms' }}
          />
        </div>
      </div>
    </div>
  )
}
