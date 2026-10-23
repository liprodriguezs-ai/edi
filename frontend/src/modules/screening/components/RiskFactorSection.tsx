import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import type { IBankRiskFactor } from '@/modules/screening/schema'
import { RISK_FACTORS_ANCHOR } from '@/modules/screening/utils/captureOverview'
import { lockedReasonCopy } from '@/modules/screening/utils/lockedReasonCopy'
import { Lock } from 'lucide-react'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'

interface RiskFactorSectionProps {
  riskFactors: IBankRiskFactor[]
  answers: Record<string, boolean>
  onChange: (_riskFactorId: string, _isPresent: boolean) => void
}

/**
 * REQ-SUI-009 — filas Sí/No de factores de riesgo biológico; la variante
 * bloqueada muestra su razón estructurada.
 *
 * Sistema: mismo lenguaje que AreaSection — kicker mono, celdas que se tocan por
 * hairline, y opciones como control segmentado con estado de acento.
 */
export function RiskFactorSection({ riskFactors, answers, onChange }: RiskFactorSectionProps) {
  if (riskFactors.length === 0) return null

  return (
    <section id={RISK_FACTORS_ANCHOR} className="scroll-mt-28">
      <SectionLabel as="h2" className="mb-2.5">
        Factores de riesgo
      </SectionLabel>
      <div className="divide-y divide-border border border-border bg-card">
        {riskFactors.map((factor) => {
          const current =
            answers[factor.risk_factor_id] === undefined ? undefined : answers[factor.risk_factor_id] ? 'yes' : 'no'
          return (
            <div key={factor.risk_factor_id} className="px-4 py-3">
              <div className="flex items-center justify-between gap-4">
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-medium leading-snug">{factor.question}</p>
                  {factor.locked && (
                    <p className="mt-1 text-xs text-muted-foreground">{lockedReasonCopy(factor.locked_reason)}</p>
                  )}
                </div>

                {factor.locked ? (
                  <Lock className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                ) : (
                  <RadioGroup
                    value={current ?? ''}
                    onValueChange={(next) => onChange(factor.risk_factor_id, next === 'yes')}
                    className="flex shrink-0 border border-border"
                    aria-label={factor.question}
                  >
                    {(
                      [
                        { v: 'yes', label: 'Sí' },
                        { v: 'no', label: 'No' },
                      ] as const
                    ).map((opt, idx) => {
                      const id = `rf-${factor.risk_factor_id}-${opt.v}`
                      const checked = current === opt.v
                      return (
                        <div key={opt.v}>
                          <RadioGroupItem id={id} value={opt.v} className="peer sr-only" />
                          <label
                            htmlFor={id}
                            className={`flex h-9 min-w-[52px] cursor-pointer select-none items-center justify-center whitespace-nowrap px-4 text-[13px] transition-colors peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-ring ${idx > 0 ? 'border-l border-border ' : ''}${
                              checked
                                ? 'bg-primary font-semibold text-primary-foreground'
                                : 'bg-card font-medium text-muted-foreground hover:bg-muted hover:text-foreground'
                            }`}
                          >
                            {opt.label}
                          </label>
                        </div>
                      )
                    })}
                  </RadioGroup>
                )}
              </div>
            </div>
          )
        })}
      </div>
    </section>
  )
}
