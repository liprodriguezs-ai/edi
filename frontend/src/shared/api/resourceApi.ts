import { apiClient } from '@/shared/api/apiClient'
import type { PaginatedResponse } from '@/shared/interfaces/Entity'

export interface CrudApiConfig<TRecord, TForm, TCustomMethods = Record<string, unknown>> {
  basePath: string
  mapListResponse?: (_payload: PaginatedResponse<TRecord>) => PaginatedResponse<TRecord>
  mapCreateInput?: (_payload: TForm) => unknown
  mapUpdateInput?: (_payload: TForm) => unknown
  customMethods?: (_basePath: string) => TCustomMethods
}

export interface ReportParams {
  _model: string
  _type?: 'xlsx' | 'csv' | 'pdf'
  _title?: string
  _report_type?: string; // Tipo de reporte específico (ej: 'recovery', 'colocation')
  [key: string]: unknown
}

type BaseCrudMethods<TRecord, TForm, TParams> = {
  readonly list: (_params: TParams) => Promise<PaginatedResponse<TRecord>>
  readonly search: (_params: TParams) => Promise<PaginatedResponse<TRecord>>
  readonly show: (_id: string, _params?: Partial<TForm>) => Promise<TRecord>
  readonly create: (_payload: TForm | FormData) => Promise<TRecord>
  readonly update: (_id: string, _payload: TForm | FormData) => Promise<TRecord>
  readonly remove: (_id: string) => Promise<string>
  readonly report: (_params: ReportParams) => Promise<Blob>
}

export const createCrudApi = <
  TRecord extends Record<string, unknown>,
  TForm extends Record<string, unknown> = TRecord,
  TParams = Record<string, unknown>,
  TCustomMethods = Record<string, unknown>,
>({
  basePath,
  mapListResponse,
  mapCreateInput,
  mapUpdateInput,
  customMethods
}: CrudApiConfig<TRecord, TForm, TCustomMethods>): BaseCrudMethods<TRecord, TForm, TParams> & TCustomMethods => {
  const list = async (params: TParams) => {
    const { data } = await apiClient.get<PaginatedResponse<TRecord>>(basePath, { params })
    return mapListResponse ? mapListResponse(data) : data
  }

  const search = async (params: TParams) => {
    const { active, page, ...bodyParams } = params as Record<string, unknown>

    const queryParams: Record<string, string> = {}
    if (active !== undefined) {
      queryParams.active = String(active)
    }
    if (page !== undefined) {
      queryParams.page = String(page)
    }

    const url = Object.keys(queryParams).length > 0
      ? `${basePath}/search?${new URLSearchParams(queryParams).toString()}`
      : `${basePath}/search`

    const { data } = await apiClient.post<PaginatedResponse<TRecord>>(url, bodyParams)
    return mapListResponse ? mapListResponse(data) : data
  }

  const create = async (payload: TForm | FormData) => {
    if (payload instanceof FormData) {
      const { data } = await apiClient.post<{ data: TRecord }>(basePath, payload)
      return data.data
    }
    const body = mapCreateInput ? mapCreateInput(payload) : payload
    const queryParams = setQueryParams(payload)
    const { data } = await apiClient.post<{ data: TRecord }>(`${basePath}?${queryParams.toString()}`, body)
    return data.data
  }

  const setQueryParams = (payload: TForm) => {
    const includeParam: Record<string, string> = {}
    if (payload.include) {
      includeParam.include = String(payload.include)
    }
    return new URLSearchParams(includeParam)
  }

  const update = async (id: string, payload: Partial<TForm> | Partial<FormData>) => {
    if (payload instanceof FormData) {
      const { data } = await apiClient.put<{ data: TRecord }>(`${basePath}/${id}`, payload)
      return data.data
    }
    const body = mapUpdateInput ? mapUpdateInput(payload as TForm) : payload as TForm
    const queryParams = setQueryParams(payload as TForm)
    const { data } = await apiClient.put<TRecord>(`${basePath}/${id}?${queryParams.toString()}`, body)
    return data
  }

  const show = async (id: string, params?: Partial<TForm>) => {
    const { data } = await apiClient.get<{ data: TRecord }>(`${basePath}/${id}`, { params })
    return data.data
  }

  const remove = async (id: string) => {
    await apiClient.delete(`${basePath}/${id}`)
    return id
  }

  const report = async (params: ReportParams): Promise<Blob> => {
    const response = await apiClient.post(`/customReport`, params, {
      responseType: 'blob',
      timeout: 0, // 60 seconds for large reports
    })
    return response.data
  }

  const baseMethods = { list, search, show, create, update, remove, report } as const

  const custom = customMethods ? customMethods(basePath) : ({} as TCustomMethods)

  return { ...baseMethods, ...custom } as BaseCrudMethods<TRecord, TForm, TParams> & TCustomMethods
}
