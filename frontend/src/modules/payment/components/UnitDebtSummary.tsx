import type { ReactNode } from 'react'
import { formatMinorUnits } from '@/shared/utils/money'

interface UnitDebtSummaryProps {
  /** Deuda total de la unidad (Σ saldos pendientes), en minor units. */
  outstandingMinor: number
  /** Saldo a favor de la unidad, en minor units (solo se muestra si > 0). */
  creditMinor: number
  /** Cargos pendientes (no cancelados, con saldo > 0). */
  pendingCount: number
  /**
   * Línea opcional "después de este pago…" — se renderiza como un renglón
   * propio DENTRO del bloque (separado por una línea), nunca encima de un input.
   */
  afterHint?: ReactNode
}

/**
 * Resumen financiero compacto de la unidad para los paneles de pago: deja a la
 * vista la DEUDA TOTAL (suma de "faltan") sin que el admin tenga que sumar a
 * mano la vista previa de aplicación. Es un BLOQUE limpio de ancho completo,
 * en su propia fila — los datos se apilan en vertical para que nada se encime
 * ni se recorte a ancho angosto (~390px). Reutiliza la misma data del estado
 * de cuenta (`statement.totals` + `credit_balance`, objetos Money serializados).
 */
export function UnitDebtSummary({
  outstandingMinor,
  creditMinor,
  pendingCount,
  afterHint,
}: UnitDebtSummaryProps) {
  const hasDebt = outstandingMinor > 0
  const hasCredit = creditMinor > 0

  if (!hasDebt) {
    return (
      <div className='rounded-[12px] border border-[var(--green)]/30 bg-[var(--green-bg)] px-4 py-3'>
        <div className='text-[13px] font-semibold text-[var(--green-deep)]'>
          Al corriente · sin deuda
        </div>
        {hasCredit && (
          <div className='mt-1 text-[12px] text-[var(--ink-3)]'>
            Saldo a favor{' '}
            <b className='font-semibold text-[#3F6E8E]'>{formatMinorUnits(creditMinor)}</b>
          </div>
        )}
      </div>
    )
  }

  return (
    <div className='rounded-[12px] border border-border bg-[var(--surface-2)] px-4 py-3.5'>
      {/* Deuda total: número destacado, en su propio renglón */}
      <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Deuda total
      </div>
      <div className='mt-1 text-[26px] font-semibold leading-none tabular-nums text-[#C0533C]'>
        {formatMinorUnits(outstandingMinor)}
      </div>

      {/* Meta: cargos pendientes + saldo a favor — envuelven sin encimarse */}
      <div className='mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-[var(--ink-3)]'>
        <span>
          {pendingCount} {pendingCount === 1 ? 'cargo pendiente' : 'cargos pendientes'}
        </span>
        {hasCredit && (
          <span className='inline-flex items-center gap-1'>
            <span aria-hidden className='text-[var(--ink-4)]'>
              ·
            </span>
            saldo a favor{' '}
            <b className='font-semibold text-[#3F6E8E]'>{formatMinorUnits(creditMinor)}</b>
          </span>
        )}
      </div>

      {afterHint && (
        <div className='mt-3 border-t border-border pt-2.5 text-[12px] text-[var(--ink-3)]'>
          {afterHint}
        </div>
      )}
    </div>
  )
}
