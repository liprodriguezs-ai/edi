import type { IProjectContribution } from '@/modules/communityProject/schema'
import { ProjectContributionResource } from '@/modules/communityProject/services/ProjectContributionResource'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { toast } from '@/shared/hooks/useToast'
import type { Filter, IPagination, Include } from '@/shared/interfaces/list.types'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'

/** Filtro de declinadas de la lista de aportaciones. */
export type DeclineFilter = 'all' | 'active' | 'declined'

interface UseContributionsListParams {
  /** Modo detalle: acota la lista a un proyecto. Ausente = lista global (solo mesa). */
  projectId?: string
  /** Incluye la relación `project` para poder pintar la columna Proyecto en la lista global. */
  showProjectColumn?: boolean
}

/**
 * Hook de datos de la lista de aportaciones compartida entre el detalle del proyecto
 * y la página "Aportaciones". Hace UNA sola búsqueda paginada de servidor
 * (`ProjectContributionResource.search`) con búsqueda + filtros de estado/declinadas.
 * El scoping de privacidad lo aplica el backend; acá solo se arma la query.
 */
export function useContributionsList({ projectId, showProjectColumn }: UseContributionsListParams) {
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(APP_CONFIG.pageSize)
  const [search, setSearchValue] = useState('')
  const [statusIds, setStatusIdsValue] = useState<string[]>([])
  const [declineFilter, setDeclineFilterValue] = useState<DeclineFilter>('all')
  const [isExporting, setIsExporting] = useState(false)

  const filters = useMemo<Filter[]>(() => {
    const built: Filter[] = []
    if (projectId) built.push({ field: 'community_project_id', operator: '=', value: projectId })
    if (statusIds.length > 0)
      built.push({ field: 'project_contribution_status_id', operator: 'in', value: statusIds })
    if (declineFilter === 'declined') built.push({ field: 'declined', operator: '=', value: true })
    if (declineFilter === 'active') built.push({ field: 'declined', operator: '=', value: false })
    return built
  }, [projectId, statusIds, declineFilter])

  const includes = useMemo<Include[]>(() => {
    const built: Include[] = [{ relation: 'unit' }, { relation: 'contributionStatus' }]
    if (showProjectColumn) built.push({ relation: 'project' })
    return built
  }, [showProjectColumn])

  const query = useQuery({
    queryKey: [
      'project-contribution',
      'list',
      { projectId, showProjectColumn, page, limit, search, statusIds, declineFilter },
    ],
    queryFn: () =>
      ProjectContributionResource.search({
        page,
        limit,
        filters,
        search: search ? { value: search, case_sensitive: false } : null,
        sort: [{ field: 'created_at', direction: 'desc' }],
        includes,
      }),
    placeholderData: keepPreviousData,
  })

  const meta = query.data?.meta
  const contributions = query.data?.data ?? []
  const pagination = useMemo<IPagination>(
    () => ({
      page: meta?.current_page ?? page,
      limit: meta?.per_page ?? limit,
      total: meta?.total ?? contributions.length,
      pages: meta?.last_page ?? 1,
      per_page: meta?.per_page ?? limit,
      to: meta?.to ?? null,
      from: meta?.from ?? null,
    }),
    [meta, page, limit, contributions.length]
  )

  const onPageChanged = useCallback((nextPage: number, nextSize?: number) => {
    setPage(nextPage)
    if (nextSize) setLimit(nextSize)
  }, [])

  const setSearch = useCallback((value: string) => {
    setSearchValue(value)
    setPage(1)
  }, [])

  const setStatusIds = useCallback((next: string[]) => {
    setStatusIdsValue(next)
    setPage(1)
  }, [])

  const setDeclineFilter = useCallback((next: DeclineFilter) => {
    setDeclineFilterValue(next)
    setPage(1)
  }, [])

  const resetFilters = useCallback(() => {
    setSearchValue('')
    setStatusIdsValue([])
    setDeclineFilterValue('all')
    setPage(1)
  }, [])

  /**
   * Exporta las aportaciones visibles (mismos filtros/búsqueda que la lista) a xlsx.
   * Nombrado `exportXlsx` (no `onReport`) porque ese nombre ya denota "reportar el pago
   * de una aportación" en ContributionsTable.tsx.
   */
  const exportXlsx = useCallback(async () => {
    if (search.trim()) {
      toast.info(
        'La búsqueda de texto no se aplica al archivo exportado; usa los filtros para acotar.'
      )
    }

    setIsExporting(true)
    try {
      const blob = await ProjectContributionResource.report({
        _model: 'ProjectContribution',
        _type: 'xlsx',
        _title: 'aportaciones',
        filters,
        ...(search ? { search: { value: search, case_sensitive: false } } : {}),
        sort: [{ field: 'created_at', direction: 'desc' }],
      })
      if (blob.size <= 0) {
        toast.info('No hay datos para exportar')
        return
      }
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = 'aportaciones.xlsx'
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
      toast.success('Reporte descargado correctamente')
    } catch {
      toast.error('Error al descargar el reporte, inténtalo más tarde')
    } finally {
      setIsExporting(false)
    }
  }, [filters, search])

  return {
    contributions: contributions as IProjectContribution[],
    pagination,
    isLoading: query.isLoading,
    search,
    statusIds,
    declineFilter,
    onPageChanged,
    setSearch,
    setStatusIds,
    setDeclineFilter,
    resetFilters,
    exportXlsx,
    isExporting,
  }
}
