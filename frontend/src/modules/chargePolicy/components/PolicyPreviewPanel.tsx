import { ChargeLifecycleTimeline } from '@/shared/components/molecules/ChargeLifecycleTimeline'
import type {
  ConcreteLifecycleMilestones,
  LifecycleMilestones,
} from '@/shared/utils/chargeLifecycle'
import { CalendarClock, Settings2, SlidersHorizontal } from 'lucide-react'
import { Link } from 'react-router-dom'

const SECTION = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const SECTION_TITLE =
  'mb-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'

interface PolicyPreviewPanelProps {
  /** Eventual = cobro directo (fechas explícitas); resto = periódico (paramétrico). */
  isEventual: boolean
  /** Hitos concretos del cobro directo (cuando hay ambas fechas). */
  concreteMilestones: ConcreteLifecycleMilestones | null
  /** Hitos paramétricos del cobro periódico (cuando hay periodicidad). */
  parametricMilestones: LifecycleMilestones | null
  /** Día de generación del clúster (solo relevante para periódico). */
  generationDay: number
  /** Días "por vencer" efectivos (override de la regla o valor del clúster). */
  effectiveDueSoonDays: number
  /** true si la regla sobreescribe los días "por vencer" del clúster. */
  dueSoonOverridden: boolean
  /** Días de gracia efectivos (override de la regla o valor del clúster). */
  effectiveGraceDays: number
  /** true si la regla sobreescribe los días de gracia del clúster. */
  graceOverridden: boolean
}

/** Fila etiquetada del bloque de configuración de tiempos. */
function TimingRow({ label, value, note }: { label: string; value: number; note: string }) {
  return (
    <div className='flex items-baseline justify-between gap-3'>
      <span className='text-[var(--ink-3)]'>{label}</span>
      <span className='text-right'>
        <span className='font-semibold tabular-nums text-foreground'>{value}</span>{' '}
        <span className='text-[11px] text-[var(--ink-3)]'>({note})</span>
      </span>
    </div>
  )
}

/**
 * Columna de PREVIEW (consecuencia) del cockpit: muestra el ciclo de vida que tendrá
 * cada cargo generado por la regla, más los tiempos que vienen del clúster (con enlace
 * a Configuración para editarlos). Es solo lectura: vive fuera del form.
 */
export function PolicyPreviewPanel({
  isEventual,
  concreteMilestones,
  parametricMilestones,
  generationDay,
  effectiveDueSoonDays,
  dueSoonOverridden,
  effectiveGraceDays,
  graceOverridden,
}: PolicyPreviewPanelProps) {
  const hasTimeline = isEventual ? concreteMilestones != null : parametricMilestones != null

  return (
    <section className={SECTION}>
      <h3 className={SECTION_TITLE}>Vista previa del ciclo</h3>
      <p className='mb-4 text-[12.5px] text-[var(--ink-3)]'>
        Así avanzará cada cargo que genere esta programación.
      </p>

      {!hasTimeline && (
        <div className='flex items-start gap-2 rounded-[10px] bg-[var(--surface-2)] px-3.5 py-3 text-[12.5px] leading-relaxed text-[var(--ink-3)]'>
          <CalendarClock className='mt-0.5 size-4 flex-none' aria-hidden />
          <span>
            {isEventual
              ? 'Completa la fecha de generación y la de vencimiento para ver el ciclo de vida.'
              : 'Elige una periodicidad para ver el ciclo de vida.'}
          </span>
        </div>
      )}

      {isEventual && concreteMilestones && (
        <ChargeLifecycleTimeline mode='concrete' milestones={concreteMilestones} />
      )}
      {!isEventual && parametricMilestones && (
        <ChargeLifecycleTimeline mode='parametric' milestones={parametricMilestones} />
      )}

      <div className='mt-5 space-y-2.5 border-t border-border pt-4 text-[12.5px]'>
        {!isEventual && (
          <TimingRow label='Día de generación' value={generationDay} note='config del condominio' />
        )}
        <TimingRow
          label='Días para «por vencer»'
          value={effectiveDueSoonDays}
          note={dueSoonOverridden ? 'override de esta programación' : 'config del condominio'}
        />
        <TimingRow
          label='Días de gracia'
          value={effectiveGraceDays}
          note={graceOverridden ? 'override de esta programación' : 'config del condominio'}
        />
        <Link
          to='/configuration'
          className='mt-1 inline-flex items-center gap-1.5 text-[12px] font-medium text-[var(--green-deep)] underline-offset-2 hover:underline'
        >
          {dueSoonOverridden ? (
            <SlidersHorizontal className='size-3.5' aria-hidden />
          ) : (
            <Settings2 className='size-3.5' aria-hidden />
          )}
          Editar configuración del condominio
        </Link>
      </div>
    </section>
  )
}
