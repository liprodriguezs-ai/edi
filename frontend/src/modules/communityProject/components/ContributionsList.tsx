import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ContributeModal } from '@/modules/communityProject/components/ContributeModal'
import { ReportPaymentModal } from '@/modules/communityProject/components/ReportPaymentModal'
import {
  ContributionsTable,
  type RowActionKind,
} from '@/modules/communityProject/components/ContributionsTable'
import {
  useContributionsList,
  type DeclineFilter,
} from '@/modules/communityProject/hooks/useContributionsList'
import {
  COMMUNITY_PROJECT_STATUS,
  PARTICIPATION_TYPE,
  PROJECT_CONTRIBUTION_STATUS,
  type ICommunityProject,
  type IProjectContribution,
} from '@/modules/communityProject/schema'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { ProjectContributionResource } from '@/modules/communityProject/services/ProjectContributionResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { moneyToMinorUnits } from '@/shared/utils/money'
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Loader2 } from 'lucide-react'
import { useCallback, useMemo, useState, type ReactNode } from 'react'

function projectIsContributable(project: ICommunityProject | null | undefined): boolean {
  if (!project) return false
  return (
    project.participation_type_id === PARTICIPATION_TYPE.VOLUNTARY &&
    (project.community_project_status_id === COMMUNITY_PROJECT_STATUS.ACTIVE ||
      project.community_project_status_id === COMMUNITY_PROJECT_STATUS.FULLY_FUNDED)
  )
}

function isDeclined(contribution: IProjectContribution): boolean {
  return (
    contribution.declined === true ||
    contribution.project_contribution_status_id === PROJECT_CONTRIBUTION_STATUS.DECLINED
  )
}

interface ContributionsListProps {
  /** Modo detalle: acota a un proyecto y aporta su contexto (estado/tipo/flag/título). */
  projectId?: string
  project?: ICommunityProject
  /** Lista global (solo mesa): muestra la columna Proyecto y busca por título. */
  showProjectColumn?: boolean
  /**
   * Encabezado standalone: recibe el botón Descargar ya renderizado para que el consumidor
   * decida su layout (la página lo pasa al `actions` de su PageHeader). Sin esto se conserva
   * la fila `<h3>` + botón del modo detalle.
   */
  renderHeader?: (exportButton: ReactNode) => ReactNode
}

/**
 * Lista de aportaciones UNIFICADA (§11): el mismo componente vive en el detalle del
 * proyecto y en la página "Aportaciones". Trae los datos por dentro (búsqueda + filtros +
 * paginación de servidor) y orquesta las acciones por rol Y por fila — la mesa aporta y
 * declina/revierte en cualquier fila contribuible; un residente solo reporta el pago de
 * SUS unidades. El scoping de privacidad lo garantiza el backend.
 */
export function ContributionsList({
  projectId,
  project,
  showProjectColumn = false,
  renderHeader,
}: ContributionsListProps) {
  const queryClient = useQueryClient()
  const isBoard = usePermissions('update.project_contribution')

  const list = useContributionsList({ projectId, showProjectColumn })
  const { contributions, pagination, exportXlsx, isExporting } = list

  const [contributeRow, setContributeRow] = useState<IProjectContribution | null>(null)
  const [reportRow, setReportRow] = useState<IProjectContribution | null>(null)

  // Unidades del residente: necesarias para saber en qué filas puede reportar. La mesa no
  // las necesita (puede actuar en cualquier fila), así que solo se piden para no-mesa.
  const { data: myUnits = [] } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
    enabled: !isBoard,
  })
  const myUnitIds = useMemo(() => new Set(myUnits.map((unit) => unit.id)), [myUnits])

  const isRowContributable = useCallback(
    (row: IProjectContribution) => projectIsContributable(projectId ? project : row.project),
    [projectId, project]
  )

  // Mesa (update.project_contribution): aporta directo. Residente miembro de la unidad:
  // reporta un pago a revisión. En filas ajenas o no contribuibles: sin acción.
  const getRowAction = useCallback(
    (row: IProjectContribution): RowActionKind => {
      if (!isRowContributable(row) || isDeclined(row)) return null
      if (isBoard) return 'contribute'
      if (myUnitIds.has(row.unit_id)) return 'report'
      return null
    },
    [isRowContributable, isBoard, myUnitIds]
  )

  const showActionsColumn = useMemo(() => {
    if (isBoard) return contributions.some(isRowContributable)
    return contributions.some((row) => isRowContributable(row) && myUnitIds.has(row.unit_id))
  }, [contributions, isBoard, isRowContributable, myUnitIds])

  // Filtros visibles para la mesa siempre; para el residente solo cuando el proyecto expone
  // los pagos (flag de privacidad). Sin flag, ve su fila sin el chrome de filtros.
  const showFilters =
    isBoard || (Boolean(projectId) && project?.payments_visible_to_residents === true)

  // ── Pagos pendientes (para el pill "Pago en revisión") ──
  // Detalle: una sola consulta del proyecto. Global: una por cada proyecto contribuible de
  // la página (acotado por el tamaño de página).
  const detailContributable = Boolean(projectId) && projectIsContributable(project)
  const { data: detailPending = [] } = useQuery({
    queryKey: ['project', 'contribution-payments', projectId, 'pending'],
    queryFn: () =>
      CommunityProjectResource.contributionPayments(projectId as string, { status: 'pending' }),
    enabled: detailContributable,
  })

  const globalProjectIds = useMemo(() => {
    if (projectId) return []
    const ids = new Set<string>()
    for (const row of contributions) if (isRowContributable(row)) ids.add(row.community_project_id)
    return [...ids]
  }, [projectId, contributions, isRowContributable])

  const globalPending = useQueries({
    queries: globalProjectIds.map((pid) => ({
      queryKey: ['project', 'contribution-payments', pid, 'pending'],
      queryFn: () =>
        CommunityProjectResource.contributionPayments(pid, { status: 'pending' as const }),
    })),
  })

  const pendingReportIds = useMemo(() => {
    const set = new Set<string>()
    const payments = projectId ? detailPending : globalPending.flatMap((q) => q.data ?? [])
    for (const payment of payments) if (payment.earmark_id) set.add(payment.earmark_id)
    return set
  }, [projectId, detailPending, globalPending])

  const invalidate = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['project-contribution'] })
    void queryClient.invalidateQueries({ queryKey: ['project'] })
  }, [queryClient])

  const toggleDecline = useMutation({
    mutationFn: (contribution: IProjectContribution) =>
      contribution.declined
        ? ProjectContributionResource.revertDecline(contribution.id)
        : ProjectContributionResource.decline(contribution.id),
    onSuccess: (_result, contribution) => {
      toast.success(contribution.declined ? 'Declinación revertida' : 'Aportación declinada')
      invalidate()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo actualizar la aportación'
      ),
  })

  const statusCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.PROJECT_CONTRIBUTION_STATUS]: { _sort: 'order' } }), [])
  )
  const statusOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusCatalog.catalogs[CatalogType.PROJECT_CONTRIBUTION_STATUS] ?? []).map((status) => ({
        value: status.id,
        label: (status.name as string | undefined) ?? status.id,
      })),
    [statusCatalog.catalogs]
  )

  const isDraft =
    Boolean(projectId) && project?.community_project_status_id === COMMUNITY_PROJECT_STATUS.DRAFT
  const emptyMessage = isDraft
    ? 'Las aportaciones se generan al activar el proyecto.'
    : 'Sin aportaciones registradas.'

  const reportProjectTitle = projectId ? (project?.title ?? '') : (reportRow?.project?.title ?? '')

  const exportButton = (
    <PermissionGuard permission='report.project_contribution'>
      <Button
        variant='outline'
        onClick={() => void exportXlsx()}
        disabled={isExporting}
        className='flex items-center gap-2'
      >
        {isExporting ? (
          <Loader2 className='h-4 w-4 animate-spin' />
        ) : (
          <Download className='h-4 w-4' />
        )}
        Descargar
      </Button>
    </PermissionGuard>
  )

  return (
    <div className='space-y-4'>
      {renderHeader ? (
        renderHeader(exportButton)
      ) : (
        <div className='flex flex-wrap items-center justify-between gap-4'>
          {projectId ? (
            <h3 className='text-sm font-semibold'>Aportaciones por unidad ({pagination.total})</h3>
          ) : (
            <div />
          )}
          {exportButton}
        </div>
      )}

      {showFilters && (
        <SearchList
          paramsSearch={{ search: list.search ? { value: list.search } : null }}
          onSearch={(params) => list.setSearch((params.search?.value as string | undefined) ?? '')}
          onReset={() => list.resetFilters()}
          placeholder='Buscar por unidad o proyecto…'
        >
          <div className='flex flex-wrap gap-2'>
            <MultiSelectFilter
              label='Estado'
              options={statusOptions}
              value={list.statusIds}
              onChange={list.setStatusIds}
              placeholder='Todos los estados'
              compact
              onFirstOpen={statusCatalog.enable}
              isLoading={statusCatalog.isLoading}
            />
            <div className='flex w-full flex-col gap-1 sm:w-48'>
              <label className='text-xs font-medium text-muted-foreground'>Declinadas</label>
              <Select
                value={list.declineFilter}
                onValueChange={(value) => list.setDeclineFilter(value as DeclineFilter)}
              >
                <SelectTrigger className='h-9 w-full bg-card font-normal'>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value='all'>Todas</SelectItem>
                  <SelectItem value='active'>Activas</SelectItem>
                  <SelectItem value='declined'>Declinadas</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </SearchList>
      )}

      <ContributionsTable
        contributions={contributions}
        showProjectColumn={showProjectColumn}
        showActionsColumn={showActionsColumn}
        emptyMessage={emptyMessage}
        canDecline={isBoard}
        isTogglingDecline={toggleDecline.isPending}
        pendingReportIds={pendingReportIds}
        getRowAction={getRowAction}
        isRowContributable={isRowContributable}
        onContribute={setContributeRow}
        onReport={setReportRow}
        onToggleDecline={(row) => toggleDecline.mutate(row)}
      />

      {pagination.total > 0 && (
        <Pagination pagination={pagination} onPageChanged={list.onPageChanged} />
      )}

      {contributeRow && (
        <ContributeModal
          isOpen
          onClose={() => setContributeRow(null)}
          contributionId={contributeRow.id}
          unitId={contributeRow.unit_id}
          unitLabel={contributeRow.unit?.full_identifier ?? contributeRow.unit?.number ?? 'unidad'}
          expectedMinor={moneyToMinorUnits(contributeRow.expected_amount ?? 0)}
          onContributed={invalidate}
        />
      )}

      {reportRow && (
        <ReportPaymentModal
          isOpen
          onClose={() => setReportRow(null)}
          contributionId={reportRow.id}
          unitId={reportRow.unit_id}
          unitLabel={reportRow.unit?.full_identifier ?? reportRow.unit?.number ?? 'unidad'}
          projectTitle={reportProjectTitle}
          expectedMinor={moneyToMinorUnits(reportRow.expected_amount ?? 0)}
          onReported={invalidate}
        />
      )}
    </div>
  )
}
