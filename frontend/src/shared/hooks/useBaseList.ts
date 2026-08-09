import type { ReportParams } from '@/shared/api/resourceApi'
import {
  buildSearchExtras,
  mergeFilterParams,
  mergeFilterParamsNoOverride,
  paginationFromHeuristic,
  paginationFromMeta,
  resolvePagination,
} from '@/shared/hooks/baseListParams'
import { useInitOnce } from '@/shared/hooks/useInitOnce'
import { toast } from '@/shared/hooks/useToast'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { IPaginationMeta, ISearchParams } from '@/shared/interfaces/list.types'
import type { IEntityStore } from '@/shared/store/EntityStore'
import { useCallback, useRef, useState } from 'react'

// Re-export so consumers that import OverrideParams from this module keep working.
export type { OverrideParams } from '@/shared/hooks/baseListParams'

interface ApiResponse<T> {
  data: T[]
  meta?: IPaginationMeta
  total?: number
}

interface ResourceApi<T> {
  list: (_params: Record<string, unknown>) => Promise<ApiResponse<T>>
  search: (_params: Record<string, unknown>) => Promise<ApiResponse<T>>
  remove?: (_id: string) => Promise<unknown>
  restore?: (_id: string) => Promise<unknown>
  report?: (_params: ReportParams) => Promise<Blob>
}

import type { OverrideParams } from '@/shared/hooks/baseListParams'

export interface UseBaseListConfig<T extends IEntity> {
  store: () => IEntityStore<T>
  resource: ResourceApi<T>
  autoInit?: boolean
  overrideParams?: OverrideParams
  initialSearch?: ISearchParams
  view?: string
}

/**
 * Base List Hook — composition-based CRUD list logic.
 * Store actions are accessed via refs to keep callbacks stable
 * and avoid cascading re-creations on every store state change.
 */
export function useBaseList<T extends IEntity>(config: UseBaseListConfig<T>) {
  const store = config.store()

  // Ref keeps the latest store snapshot so callbacks don't need
  // `store` in their dependency arrays (which changes every render).
  const storeRef = useRef(store)
  storeRef.current = store

  // Local export flag — kept separate from the store's global `isLoading`
  // so downloading a report doesn't flip the whole list into skeleton state.
  const [isExporting, setIsExporting] = useState(false)

  const [paramsSearch, setParamsSearch] = useState<ISearchParams>(
    config.initialSearch || {
      search: null,
      filters: [],
      sort: [],
      includes: [],
      aggregates: [],
      user_id: null,
    }
  )
  const paramsSearchRef = useRef(paramsSearch)
  paramsSearchRef.current = paramsSearch

  const buildParams = useCallback(
    (overrides?: { page?: number; limit?: number }, searchOverrides?: Partial<ISearchParams>) => {
      const currentStore = storeRef.current
      const currentSearch = searchOverrides
        ? { ...paramsSearchRef.current, ...searchOverrides }
        : paramsSearchRef.current

      const { page, limit } = resolvePagination(currentStore.pagination, currentSearch, overrides)

      const params: Record<string, unknown> = {
        page,
        limit,
        view: config.view,
      }

      const filterPart = config.overrideParams
        ? mergeFilterParams(currentSearch, config.overrideParams)
        : mergeFilterParamsNoOverride(currentSearch)

      Object.assign(params, filterPart)
      Object.assign(params, buildSearchExtras(currentSearch))

      return params
    },
    [config.overrideParams, config.view]
  )

  const getList = useCallback(
    async (
      paramOverrides?: { page?: number; limit?: number },
      searchOverrides?: Partial<ISearchParams>
    ) => {
      const s = storeRef.current
      s.putLoading(true)
      s.putNotFound(false)

      try {
        const params = buildParams(paramOverrides, searchOverrides)
        const response = await config.resource.search(params)

        let paginationUpdate
        let metaUpdate: IPaginationMeta | undefined

        if (response.meta && typeof response.meta === 'object') {
          paginationUpdate = paginationFromMeta(
            response.meta,
            params,
            response.data.length,
            response.total
          )
          metaUpdate = response.meta
        } else {
          paginationUpdate = paginationFromHeuristic(params, response.data.length, response.total)
        }

        // Un único set(): entities + meta + pagination + notFound en un solo snapshot
        s.setListResult({
          entities: response.data,
          meta: metaUpdate,
          pagination: paginationUpdate,
          notFound: response.data.length === 0,
        })

        return response
      } catch (error) {
        s.removeAllEntities()
        s.putMeta(null)
        toast.error('Error al obtener la lista')
        throw error
      } finally {
        s.putLoading(false)
      }
    },
    [buildParams, config.resource]
  )

  const onSearch = useCallback(
    (search?: Partial<ISearchParams>) => {
      if (search) {
        setParamsSearch((prev) => ({ ...prev, ...search }))
      }
      storeRef.current.putPagination({ page: 1 })
      return getList({ page: 1 }, search)
    },
    [getList]
  )

  const onReset = useCallback(() => {
    const resetSearch: ISearchParams = {
      search: null,
      filters: [],
      sort: paramsSearchRef.current.sort,
      includes: paramsSearchRef.current.includes,
      aggregates: [],
      user_id: null,
      owner_ids: undefined,
    }
    setParamsSearch(resetSearch)
    storeRef.current.putPagination({ page: 1 })
    return getList({ page: 1 }, resetSearch)
  }, [getList])

  const onPageChanged = useCallback(
    (page: number, limit?: number) => {
      const paginationUpdate = {
        page,
        ...(limit ? { limit } : {}),
      }
      storeRef.current.putPagination(paginationUpdate)
      return getList(paginationUpdate)
    },
    [getList]
  )

  const onRemove = useCallback(
    async (entity: T) => {
      if (!config.resource.remove) return

      const s = storeRef.current
      s.putDeleting(true)
      try {
        await config.resource.remove(entity.id.toString())
        s.removeEntity(entity.id)
        toast.success('Registro eliminado exitosamente')
      } catch (error) {
        toast.error('Error al eliminar el registro')
        throw error
      } finally {
        s.putDeleting(false)
      }
    },
    [config.resource]
  )

  const onRestore = useCallback(
    async (entity: T) => {
      if (!config.resource.restore) return

      const s = storeRef.current
      s.putSaving(true)
      try {
        await config.resource.restore(entity.id.toString())
        toast.success('Registro restaurado exitosamente')
        await getList()
      } catch (error) {
        toast.error('Error al restaurar el registro')
        throw error
      } finally {
        s.putSaving(false)
      }
    },
    [config.resource, getList]
  )

  const onReport = useCallback(
    async (
      model: string,
      type: 'xlsx' | 'csv' | 'pdf' = 'xlsx',
      title: string = 'reporte',
      reportType?: string
    ) => {
      if (!config.resource.report) {
        toast.error('La descarga de reportes no está disponible')
        return
      }

      const activeSearchValue = paramsSearchRef.current.search?.value?.trim()
      const hasActiveOwnerFilter = (paramsSearchRef.current.owner_ids?.length ?? 0) > 0
      if (activeSearchValue || hasActiveOwnerFilter) {
        toast.info(
          'Algunos filtros (búsqueda de texto, responsable de pago) no se aplican al archivo exportado; usa los demás filtros para acotar.'
        )
      }

      setIsExporting(true)

      try {
        const params = buildParams()
        const reportParams: ReportParams = {
          _model: model,
          _type: type,
          _title: title,
          _report_type: reportType,
          limit: params.limit as number,
          page: params.page as number,
          ...params,
        }

        const blob = await config.resource.report(reportParams)

        if (blob.size <= 0) {
          toast.info('No hay datos para exportar')
          return
        }

        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `${title}.${type}`
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)

        toast.success('Reporte descargado correctamente')
      } catch {
        // No rethrow: every call site fires this from onClick without
        // awaiting/catching (`onClick={() => onReport(...)}`). The toast
        // above already surfaces the error to the user; rethrowing here
        // only produced an unhandled promise rejection in the console.
        toast.error('Error al descargar el reporte, inténtalo más tarde')
      } finally {
        setIsExporting(false)
      }
    },
    [buildParams, config.resource]
  )

  useInitOnce(() => {
    if (config.autoInit !== false) {
      getList()
    }
  }, 'baseList')

  return {
    store,
    paramsSearch,
    setParamsSearch,

    getList,
    onSearch,
    onReset,
    onPageChanged,
    onRemove,
    onRestore,
    onReport,
    isExporting,
  }
}
