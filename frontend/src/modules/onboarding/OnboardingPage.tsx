import { useOnboardingChecklist } from '@/modules/onboarding/hooks/useOnboardingChecklist'
import type { IOnboardingItem } from '@/modules/onboarding/schema'
import { CheckCircle2, Circle, ChevronRight, Loader2 } from 'lucide-react'
import { Link } from 'react-router-dom'

function OnboardingItemRow({ item }: { item: IOnboardingItem }) {
  const rowClass =
    'flex items-start gap-4 rounded-xl border border-border bg-card px-4 py-4 transition-colors ' +
    (item.completed
      ? 'opacity-60'
      : 'hover:border-[var(--green)]/40 hover:bg-muted/50 cursor-pointer')

  const content = (
    <>
      <span className='mt-0.5 shrink-0' aria-hidden>
        {item.completed ? (
          <CheckCircle2 className='size-5 text-[var(--green)]' />
        ) : (
          <Circle className='size-5 text-muted-foreground/50' />
        )}
      </span>
      <div className='min-w-0 flex-1'>
        <p
          className={
            item.completed
              ? 'text-sm font-medium text-muted-foreground line-through'
              : 'text-sm font-semibold text-foreground'
          }
        >
          {item.title}
          {!item.required && (
            <span className='ml-2 rounded-full bg-muted px-2 py-0.5 text-[10px] font-normal text-muted-foreground'>
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
    return (
      <div className='flex h-64 items-center justify-center'>
        <Loader2 className='size-6 animate-spin text-muted-foreground' aria-label='Cargando…' />
      </div>
    )
  }

  if (!checklist) {
    return null
  }

  const { items, required_total, required_completed, progress_percentage, is_complete } = checklist

  return (
    <div className='mx-auto max-w-2xl space-y-6 px-4 py-8'>
      {/* Header */}
      <div>
        <h1 className='text-2xl font-semibold tracking-tight text-foreground'>
          Configura tu condominio
        </h1>
        <p className='mt-1 text-sm text-muted-foreground'>
          Completa estos pasos para comenzar a gestionar tu condominio.
        </p>
      </div>

      {/* Progress bar */}
      <div className='space-y-2'>
        <div className='flex items-center justify-between text-xs text-muted-foreground'>
          <span>
            {required_completed} de {required_total} pasos requeridos completados
          </span>
          <span className='tabular-nums'>{progress_percentage}%</span>
        </div>
        <div
          className='h-2 w-full overflow-hidden rounded-full bg-muted'
          role='progressbar'
          aria-valuenow={progress_percentage}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label='Progreso de configuración'
        >
          <div
            className='h-full rounded-full bg-[var(--green)] transition-all duration-500'
            style={{ width: `${progress_percentage}%` }}
          />
        </div>
      </div>

      {/* Completion banner */}
      {is_complete && (
        <div className='flex flex-wrap items-center gap-3 rounded-xl border border-[var(--green)]/30 bg-[var(--green)]/5 px-4 py-3 text-sm text-[var(--green)]'>
          <CheckCircle2 className='size-5 shrink-0' aria-hidden />
          <span className='flex-1 font-medium'>
            ¡Configuración completa! Tu condominio está listo para operar.
          </span>
          <Link
            to='/dashboard'
            className='rounded-lg bg-[var(--green)] px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90'
          >
            Ir al dashboard
          </Link>
        </div>
      )}

      {/* Items list */}
      <div className='space-y-2'>
        {items.map((item) => (
          <OnboardingItemRow key={item.id} item={item} />
        ))}
      </div>
    </div>
  )
}
