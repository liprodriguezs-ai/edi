import type { ReactNode } from 'react'

import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { resultColorName, resultColorTone } from '@/shared/utils/resultColor'

interface ResultColorPillProps {
  /** Server `result_color.key` (green | yellow | red). */
  colorKey: string | null | undefined
  /** Overrides the default color name (e.g. a situation label). */
  label?: ReactNode
}

/** EDI clinical semaphore pill. Mapping lives in `@/shared/utils/resultColor`. */
export function ResultColorPill({ colorKey, label }: ResultColorPillProps) {
  return <StatusPill tone={resultColorTone(colorKey)}>{label ?? resultColorName(colorKey)}</StatusPill>
}
