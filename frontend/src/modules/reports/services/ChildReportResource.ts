import type {
  IChildReportQuery,
  IEdiConcentrateRow,
  IGeneralReportRow,
} from '@/modules/reports/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import type { PaginatedResponse } from '@/shared/interfaces/Entity'
import type { Filter, Search } from '@/shared/interfaces/list.types'

/** Filter fields the per-child report pages put in `paramsSearch.filters`. */
export const CHILD_REPORT_FILTER = {
  HEALTH_CENTER: 'health_center_id',
  LAST_TEST_FROM: 'last_screening_from',
  LAST_TEST_TO: 'last_screening_to',
} as const

interface ChildReportListParams {
  page?: unknown
  limit?: unknown
  filters?: Filter[]
  search?: Search | null
}

const stringValue = (value: unknown): string | undefined =>
  typeof value === 'string' && value.trim() !== '' ? value : undefined

/**
 * Translates useBaseList's Orion-style params (filters/search/page/limit)
 * into the GET query string of `/reports/edi-concentrate` and
 * `/reports/general` (and their `/export`). Pure — unit tested.
 */
export function toChildReportQuery(params: ChildReportListParams): IChildReportQuery {
  const query: IChildReportQuery = {}

  if (typeof params.page === 'number') query.page = params.page
  if (typeof params.limit === 'number') query.limit = params.limit

  const search = stringValue(params.search?.value)
  if (search) query.search = search.trim()

  for (const filter of params.filters ?? []) {
    if (
      filter.field === CHILD_REPORT_FILTER.HEALTH_CENTER &&
      Array.isArray(filter.value) &&
      filter.value.length > 0
    ) {
      query.health_center_ids = filter.value.filter((id): id is string => typeof id === 'string')
    }
    if (filter.field === CHILD_REPORT_FILTER.LAST_TEST_FROM) query.from = stringValue(filter.value)
    if (filter.field === CHILD_REPORT_FILTER.LAST_TEST_TO) query.to = stringValue(filter.value)
  }

  return query
}

/**
 * Read-only per-child report resource. These endpoints are plain GETs (not
 * Orion resources), so `search` is overridden to call GET with the mapped
 * query string, and `exportXlsx` downloads the same filtered rows (all pages).
 */
function createChildReportResource<TRow extends IGeneralReportRow>(basePath: string) {
  return createCrudApi<
    TRow,
    TRow,
    Record<string, unknown>,
    {
      search: (_params: Record<string, unknown>) => Promise<PaginatedResponse<TRow>>
      exportXlsx: (_query: IChildReportQuery) => Promise<Blob>
    }
  >({
    basePath,
    customMethods: (path) => ({
      search: async (params: Record<string, unknown>) => {
        const { data } = await apiClient.get<PaginatedResponse<TRow>>(path, {
          params: toChildReportQuery(params as ChildReportListParams),
        })
        return data
      },
      exportXlsx: async (query: IChildReportQuery) => {
        const { data } = await apiClient.get<Blob>(`${path}/export`, {
          params: query,
          responseType: 'blob',
          timeout: 0,
        })
        return data
      },
    }),
  })
}

export const EdiConcentrateResource = createChildReportResource<IEdiConcentrateRow>(
  '/reports/edi-concentrate'
)

export const GeneralReportResource =
  createChildReportResource<IGeneralReportRow>('/reports/general')
