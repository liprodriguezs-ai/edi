import type { PillTone } from '@/shared/components/atoms/StatusPill'

/**
 * EDI clinical semaphore. The ONLY place that maps a server `result_color.key`
 * (green | yellow | red) to a visual tone and its Spanish name. Consumers pass the
 * key exactly as the server sent it — never a value computed on the client.
 */
const TONE_BY_KEY: Record<string, PillTone> = {
  green: 'g',
  yellow: 'y',
  red: 'r',
}

const NAME_BY_KEY: Record<string, string> = {
  green: 'Verde',
  yellow: 'Amarillo',
  red: 'Rojo',
}

/** Tone for a result color key; neutral when the key is missing or unknown. */
export function resultColorTone(colorKey: string | null | undefined): PillTone {
  return TONE_BY_KEY[colorKey ?? ''] ?? 'n'
}

/** Spanish name for a result color key ("Sin calificar" when missing). */
export function resultColorName(colorKey: string | null | undefined): string {
  if (!colorKey) return 'Sin calificar'
  return NAME_BY_KEY[colorKey] ?? colorKey
}
