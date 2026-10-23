import type {
  IAgeGroupOption,
  IScreening,
  IAlternateRequirement,
  IScreeningItemBank,
  ScreeningSomatometryFormValues,
} from '@/modules/screening/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import type { IInstrumentVersion } from '@/modules/instrument/schema'

export interface CreateScreeningPayload {
  visit_id: string
  weight_grams?: number | null
  height_mm?: number | null
  head_circumference_mm?: number | null
  notes?: string | null
  /** "Prueba EDI a elección": the official group picked by the evaluator. */
  age_group_id?: string
}

export interface CreateScreeningResponse {
  data: IScreening
  item_bank: IScreeningItemBank
}

export interface ItemsResponse {
  item_bank: IScreeningItemBank
  alternate_requirements?: IAlternateRequirement[]
}

export interface AnswerResponse {
  data: IScreening
  /** Axes that currently need the previous group's items. */
  alternate_requirements?: IAlternateRequirement[]
}

export interface AnswerBatchPayload {
  answers?: Array<{ screening_item_id: string; screening_item_option_id: string }>
  risk_factor_answers?: Array<{ risk_factor_id: string; is_present: boolean }>
}

export interface RescorePayload {
  reason: string
  authoritative?: boolean
  instrument_version_key?: string | null
}

export interface RescoreResponse {
  data: IScreening
  run: {
    id: string
    instrument_version_id: string
    is_authoritative: boolean
  }
}

export interface InvalidatePayload {
  reason: string
}

/**
 * `createCrudApi` serves index/show/update (Orion, somatometry+notes only —
 * REQ-SCR-003) plus every custom lifecycle action (design §4/§5). Named
 * `createScreening` on the backend controller because Orion's own `store` is
 * already declared with an incompatible signature — the client-facing method
 * here is simply `createDraft` to match the module's vocabulary.
 */
export const ScreeningResource = createCrudApi<
  IScreening,
  ScreeningSomatometryFormValues,
  Record<string, unknown>,
  {
    createDraft: (_payload: CreateScreeningPayload) => Promise<CreateScreeningResponse>
    items: (_id: string) => Promise<ItemsResponse>
    answer: (_id: string, _payload: AnswerBatchPayload) => Promise<AnswerResponse>
    score: (_id: string) => Promise<{ data: IScreening }>
    complete: (_id: string) => Promise<{ data: IScreening }>
    rescore: (_id: string, _payload: RescorePayload) => Promise<RescoreResponse>
    invalidate: (_id: string, _payload: InvalidatePayload) => Promise<{ data: IScreening }>
    findByVisitId: (_visitId: string) => Promise<IScreening | null>
    document: (_id: string) => Promise<Blob>
    rescoreVersions: () => Promise<IInstrumentVersion[]>
    ageGroups: () => Promise<IAgeGroupOption[]>
  }
>({
  basePath: '/screening',
  customMethods: (basePath) => ({
    createDraft: async (payload) => {
      const { data } = await apiClient.post<CreateScreeningResponse>(basePath, payload)
      return data
    },
    items: async (id) => {
      const { data } = await apiClient.get<ItemsResponse>(`${basePath}/${id}/items`)
      return data
    },
    answer: async (id, payload) => {
      const { data } = await apiClient.put<AnswerResponse>(`${basePath}/${id}/answer`, payload)
      return data
    },
    score: async (id) => {
      const { data } = await apiClient.post<{ data: IScreening }>(`${basePath}/${id}/score`)
      return data
    },
    complete: async (id) => {
      const { data } = await apiClient.post<{ data: IScreening }>(`${basePath}/${id}/complete`)
      return data
    },
    rescore: async (id, payload) => {
      const { data } = await apiClient.post<RescoreResponse>(`${basePath}/${id}/rescore`, payload)
      return data
    },
    invalidate: async (id, payload) => {
      const { data } = await apiClient.post<{ data: IScreening }>(`${basePath}/${id}/invalidate`, payload)
      return data
    },
    // Resolves REQ-SUI-003 VISIT_ALREADY_SCREENED by finding the existing
    // screening for a visit (design D2). filterableBy() allows visit_id;
    // Orion's filter engine is wired into /search (POST body), same pattern
    // as useBaseList/mergeFilterParams — a GET query string cannot carry a
    // nested filters[] array reliably.
    findByVisitId: async (visitId) => {
      const { data } = await apiClient.post<{ data: IScreening[] }>(`${basePath}/search?limit=1`, {
        filters: [{ field: 'visit_id', operator: '=', value: visitId }],
      })
      return data.data[0] ?? null
    },
    // Served by the Screening module itself, NOT by /instrument_version: the raw
    // instrument catalog is closed to every organization role on purpose, so
    // reading it there left rescoring unreachable for the roles that hold the
    // screenings.
    // The official FUA sheet. Server-rendered from stored data, so the client
    // just hands the bytes to the browser -- it never formats a result itself.
    document: async (id) => {
      const { data } = await apiClient.get<Blob>(`${basePath}/${id}/document`, { responseType: 'blob' })
      return data
    },
    rescoreVersions: async () => {
      const { data } = await apiClient.get<{ data: IInstrumentVersion[] }>(
        `${basePath}/instrument-versions`
      )
      return data.data
    },
    // The official groups of the default version, for "Prueba EDI a elección".
    // Served here because /age_group is a platform-admin catalog.
    ageGroups: async () => {
      const { data } = await apiClient.get<{ data: IAgeGroupOption[] }>(`${basePath}/age-groups`)
      return data.data
    },
  }),
})
