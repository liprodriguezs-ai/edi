import { periodicityCadenceLabel } from '@/modules/chargeConcept/helpers/chargeGeneration'
import { PERIODICITY, type IChargePolicy } from '@/modules/chargePolicy/schema'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'

/** Monto representativo de una política para mostrar en listas/labels. */
export function policyAmountLabel(policy: IChargePolicy): string {
  if (moneyToMinorUnits(policy.fixed_amount ?? null) > 0) return displayMoney(policy.fixed_amount)
  if (moneyToMinorUnits(policy.ideal_amount ?? null) > 0)
    return `${displayMoney(policy.ideal_amount)} (rango)`
  if (moneyToMinorUnits(policy.prorate_total_amount ?? null) > 0) {
    return `${displayMoney(policy.prorate_total_amount)} (prorrateo)`
  }
  return 'Voluntario'
}

/** Etiqueta legible de una política para el selector: concepto · monto · periodicidad. */
export function policyOptionLabel(policy: IChargePolicy): string {
  const concept = policy.concept?.code ?? policy.concept?.name ?? policy.name
  return `${concept} · ${policyAmountLabel(policy)} · ${periodicityCadenceLabel(policy.periodicity_id)}`
}

interface PolicySummaryPanelProps {
  policy: IChargePolicy | undefined
}

/**
 * Panel SOLO LECTURA con el monto/periodicidad/gracia que aplica la política
 * seleccionada. El lote ya no define su propio monto: lo hereda de la política.
 */
export function PolicySummaryPanel({ policy }: PolicySummaryPanelProps) {
  if (!policy) return null

  // El modo se DERIVA de la periodicidad: EVENTUAL es cobro único (directo); el resto, periódico.
  const isDirect = policy.periodicity_id === PERIODICITY.EVENTUAL

  return (
    <div className='rounded-[12px] border border-border bg-[var(--surface-2)] p-4'>
      <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Programación aplicada (solo lectura)
      </p>
      <dl className='grid grid-cols-2 gap-3 text-[13px] sm:grid-cols-4'>
        <div>
          <dt className='text-[var(--ink-3)]'>Monto</dt>
          <dd className='mt-0.5 font-semibold tabular-nums'>{policyAmountLabel(policy)}</dd>
        </div>
        <div>
          <dt className='text-[var(--ink-3)]'>Periodicidad</dt>
          <dd className='mt-0.5 font-medium'>{periodicityCadenceLabel(policy.periodicity_id)}</dd>
        </div>
        <div>
          <dt className='text-[var(--ink-3)]'>Días de gracia</dt>
          <dd className='mt-0.5 font-medium tabular-nums'>{policy.grace_days ?? 0}</dd>
        </div>
        <div>
          <dt className='text-[var(--ink-3)]'>Modo</dt>
          <dd className='mt-0.5 font-medium'>{isDirect ? 'Directo' : 'Periódico'}</dd>
        </div>
      </dl>
    </div>
  )
}
