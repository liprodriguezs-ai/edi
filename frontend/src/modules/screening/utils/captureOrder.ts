import type { IBankArea } from '@/modules/screening/schema'

/**
 * Official form order (formato único DGIS): biological risk factors and alert
 * signs (ALE) first, then the development axes in the manual's order (MG, MF,
 * LE, SO, CO), then the neurological exam (EN) and alarm signs (ALA). Display
 * order only -- the server sends the axes in another order. An unknown area
 * goes after the axes, keeping its server position.
 */
const CAPTURE_RANK: Record<string, number> = { ALE: 0, MG: 1, MF: 2, LE: 3, SO: 4, CO: 5, EN: 7, ALA: 8 }
const UNKNOWN_AREA_RANK = 6

/** Position of an area code in the official order (capture screen and result table share it). */
export function captureRank(code: string | null | undefined): number {
  return CAPTURE_RANK[code ?? ''] ?? UNKNOWN_AREA_RANK
}

export function orderAreasForCapture(areas: IBankArea[]): IBankArea[] {
  return [...areas].sort((a, b) => captureRank(a.code) - captureRank(b.code))
}

/** In-page anchor of an area section, used by the section chips and the review step. */
export function areaAnchorId(area: Pick<IBankArea, 'development_area_id' | 'code'>): string {
  return `area-${area.code ?? area.development_area_id ?? 'sin-codigo'}`
}
