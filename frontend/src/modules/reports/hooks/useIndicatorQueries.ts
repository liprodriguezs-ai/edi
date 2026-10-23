import { IndicatorResource } from '@/modules/reports/services/IndicatorResource'
import { useQuery } from '@tanstack/react-query'

interface IndicatorFilterParams {
  period?: string
  healthCenterId?: string
}

/** REQ-RPT-004 — KPI cards + color/situation breakdowns for one period. */
export function useIndicatorSummary({ period, healthCenterId }: IndicatorFilterParams) {
  return useQuery({
    queryKey: ['reports', 'indicators', 'summary', period, healthCenterId],
    queryFn: () => IndicatorResource.summary({ period, health_center_id: healthCenterId }),
  })
}

/** REQ-RPT-004 — trailing monthly trend. */
export function useIndicatorMonthlySeries(months: number, healthCenterId?: string) {
  return useQuery({
    queryKey: ['reports', 'indicators', 'monthly-series', months, healthCenterId],
    queryFn: () => IndicatorResource.monthlySeries({ months, health_center_id: healthCenterId }),
  })
}

/** REQ-RPT-004 — most-affected areas for one period. */
export function useIndicatorAreaBreakdown({ period, healthCenterId }: IndicatorFilterParams) {
  return useQuery({
    queryKey: ['reports', 'indicators', 'area-breakdown', period, healthCenterId],
    queryFn: () => IndicatorResource.areaBreakdown({ period, health_center_id: healthCenterId }),
  })
}

/** REQ-RPT-005 — EDI concentrate matrix (period × center). */
export function useIndicatorConcentrate(from: string, to: string, healthCenterId?: string) {
  return useQuery({
    queryKey: ['reports', 'indicators', 'concentrate', from, to, healthCenterId],
    queryFn: () => IndicatorResource.concentrate({ from, to, health_center_id: healthCenterId }),
  })
}
