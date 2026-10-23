import type { AreaNavEntry } from '@/modules/screening/components/AreaNav'
import type { IBankArea, IBankItem, IScreeningItemBank } from '@/modules/screening/schema'
import { areaProgress } from '@/modules/screening/utils/areaProgress'
import { areaAnchorId, orderAreasForCapture } from '@/modules/screening/utils/captureOrder'

export const RISK_FACTORS_ANCHOR = 'factores-riesgo'

export interface CaptureEntry extends AreaNavEntry {
  /** Items the server requires before scoring (risk factors are not). */
  required: boolean
  requiredAlternates: number
}

export interface CaptureOverview {
  /** Areas in the official form order. */
  areas: IBankArea[]
  entries: CaptureEntry[]
  answered: number
  total: number
  /** Required items still unanswered, alternates included. */
  missingRequired: number
  pendingAlternates: number
}

/**
 * Everything the capture screen reports about progress -- section chips,
 * footer counter and review step -- from one computation.
 */
export function buildCaptureOverview(
  bank: IScreeningItemBank,
  answers: Record<string, string>,
  riskFactorAnswers: Record<string, boolean>,
  alternateRequired: Record<string, IBankItem[]>
): CaptureOverview {
  const areas = orderAreasForCapture(bank.areas)

  const riskAnswered = bank.risk_factors.filter(
    (factor) => factor.locked || riskFactorAnswers[factor.risk_factor_id] !== undefined
  ).length
  const riskEntries: CaptureEntry[] =
    bank.risk_factors.length === 0
      ? []
      : [
          {
            anchorId: RISK_FACTORS_ANCHOR,
            label: 'Factores de riesgo',
            status: riskAnswered === bank.risk_factors.length ? 'complete' : 'pending',
            answered: riskAnswered,
            total: bank.risk_factors.length,
            required: false,
            requiredAlternates: 0,
          },
        ]

  let pendingAlternates = 0
  const areaEntries: CaptureEntry[] = areas.map((area) => {
    const progress = areaProgress(area, answers, alternateRequired[area.development_area_id ?? 'unknown'])
    pendingAlternates += progress.requiredAlternates - progress.answeredAlternates
    return {
      anchorId: areaAnchorId(area),
      label: area.name ?? area.code ?? 'Área',
      status: progress.status,
      answered: progress.answered,
      total: progress.total,
      required: true,
      requiredAlternates: progress.requiredAlternates,
    }
  })

  const entries = [...riskEntries, ...areaEntries]
  return {
    areas,
    entries,
    answered: entries.reduce((sum, entry) => sum + entry.answered, 0),
    total: entries.reduce((sum, entry) => sum + entry.total, 0),
    missingRequired: areaEntries.reduce((sum, entry) => sum + entry.total - entry.answered, 0),
    pendingAlternates,
  }
}
