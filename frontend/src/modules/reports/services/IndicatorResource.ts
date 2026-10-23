import type {
  IIndicatorAreaBreakdownResponse,
  IIndicatorConcentrateRow,
  IIndicatorMonthlyPoint,
  IIndicatorSummary,
} from '@/modules/reports/schema'
import { apiClient } from '@/shared/api/apiClient'

/**
 * Aggregate, PII-free indicator endpoints (REQ-RPT-004/005). Plain fetchers
 * consumed by TanStack Query directly — these are server-state KPI payloads,
 * not entities, so no `createCrudApi`/`createEntityStore` here (D11).
 */
export const IndicatorResource = {
  summary: async (params: {
    period?: string
    health_center_id?: string
  }): Promise<IIndicatorSummary> => {
    const { data } = await apiClient.get<{ data: IIndicatorSummary }>(
      '/reports/indicators/summary',
      { params }
    )
    return data.data
  },
  monthlySeries: async (params: {
    months?: number
    health_center_id?: string
  }): Promise<IIndicatorMonthlyPoint[]> => {
    const { data } = await apiClient.get<{ data: IIndicatorMonthlyPoint[] }>(
      '/reports/indicators/monthly-series',
      {
        params,
      }
    )
    return data.data
  },
  areaBreakdown: async (params: {
    period?: string
    health_center_id?: string
  }): Promise<IIndicatorAreaBreakdownResponse> => {
    const { data } = await apiClient.get<{ data: IIndicatorAreaBreakdownResponse }>(
      '/reports/indicators/area-breakdown',
      { params }
    )
    return data.data
  },
  concentrate: async (params: {
    from?: string
    to?: string
    health_center_id?: string
  }): Promise<IIndicatorConcentrateRow[]> => {
    const { data } = await apiClient.get<{ data: IIndicatorConcentrateRow[] }>(
      '/reports/indicators/concentrate',
      {
        params,
      }
    )
    return data.data
  },
}
