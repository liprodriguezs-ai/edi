import { useOnboardingChecklist } from '@/modules/onboarding/hooks/useOnboardingChecklist'
import type { IOnboardingItem } from '@/modules/onboarding/schema'
import { PageLoader } from '@/shared/components/PageLoader'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'
import { CheckCircle2, Circle, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

function OnboardingItemRow({ item }: { item: IOnboardingItem }) {
  // Fila que se toca con sus vecinas (el contenedor pone el hairline). Sin caja
  // propia, sin esquina redonda, sin gap: jerarquía por línea.
  const rowClass =
    'flex items-start gap-4 bg-background px-4 py-4 transition-colors ' +
    (item.completed ? 'opacity-60' : 'cursor-pointer hover:bg-secondary')

  const content = (
    <>
      <span className='mt-0.5 shrink-0' aria-hidden>
        {item.completed ? (
          <CheckCircle2 className='size-5 text-primary' />
        ) : (
          <Circle className='size-5 text-muted-foreground/50' />
        )}
      </span>
      <div className='min-w-0 flex-1'>
        <p
          className={
            item.completed
              ? 'flex items-center gap-2 text-sm font-medium text-muted-foreground line-through'
              : 'flex items-center gap-2 text-sm font-semibold text-foreground'
          }
        >
          {item.title}
          {!item.required && (
            <span className='border border-border px-1.5 py-0.5 font-mono text-[9.5px] uppercase tracking-[0.1em] font-normal text-muted-foreground no-underline'>
              Opcional
            </span>
          )}
        </p>
        <p className='mt-0.5 text-xs text-muted-foreground'>{item.details}</p>
      </div>
      {!item.completed && (
        <ChevronRight className='mt-0.5 size-4 shrink-0 text-muted-foreground' aria-hidden />
      )}
    </>
  )

  if (!item.completed) {
    return (
      <Link to={item.route} className={rowClass} aria-label={`Configurar: ${item.title}`}>
        {content}
      </Link>
    )
  }

  return <div className={rowClass}>{content}</div>
}

export function OnboardingPage() {
  const { checklist, isLoading } = useOnboardingChecklist()

  if (isLoading) {
    return <PageLoader />
  }

  if (!checklist) {
    return null
  }

  const { items, required_total, required_completed, progress_percentage, is_complete } = checklist

  return (
    <div className='mx-auto max-w-2xl space-y-6 px-4 py-8'>
      {/* Header */}
      <div>
        <SectionLabel className='mb-3'>Configuración inicial</SectionLabel>
        <PageHeader title='Configura tu centro' description='Completa estos pasos para comenzar a tamizar.' />
      </div>

      {/* Progress bar — barra rectangular, canto vivo */}
      <div className='space-y-2'>
        <div className='flex items-center justify-between text-xs text-muted-foreground'>
          <span>
            {required_completed} de {required_total} pasos requeridos completados
          </span>
          <span className='font-mono tabular-nums'>{progress_percentage}%</span>
        </div>
        <div
          className='h-2 w-full overflow-hidden bg-muted'
          role='progressbar'
          aria-valuenow={progress_percentage}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label='Progreso de configuración'
        >
          <div
            className='h-full bg-primary transition-all duration-500'
            style={{ width: `${progress_percentage}%` }}
          />
        </div>
      </div>

      {/* Completion banner */}
      {is_complete && (
        <div className='flex flex-wrap items-center gap-3 border-l-2 border-primary bg-primary/5 px-4 py-3 text-sm'>
          <CheckCircle2 className='size-5 shrink-0 text-primary' aria-hidden />
          <span className='flex-1 font-medium text-foreground'>
            ¡Configuración completa! Tu centro está listo para operar.
          </span>
          <Link
            to='/dashboard'
            className='bg-primary px-3 py-1.5 font-mono text-[11px] uppercase tracking-[0.08em] font-medium text-primary-foreground transition-opacity hover:opacity-90'
          >
            Ir al tablero
          </Link>
        </div>
      )}

      {/* Items list — celdas que se tocan por hairline */}
      <div className='divide-y divide-border border border-border'>
        {items.map((item) => (
          <OnboardingItemRow key={item.id} item={item} />
        ))}
      </div>
    </div>
  )
}
