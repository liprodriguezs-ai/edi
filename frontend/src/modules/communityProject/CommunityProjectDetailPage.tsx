import { Button } from '@/components/ui/button'
import {
  COMMUNITY_PROJECT_STATUS,
  PARTICIPATION_TYPE,
  type ICommunityProject,
} from '@/modules/communityProject/schema'
import { ContributionsList } from '@/modules/communityProject/components/ContributionsList'
import { ProjectDocuments } from '@/modules/communityProject/components/ProjectDocuments'
import { ProjectEditModal } from '@/modules/communityProject/components/ProjectEditModal'
// import { ProjectComments } from '@/modules/communityProject/components/ProjectComments'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { projectTone } from '@/modules/communityProject/CommunityProjectListPage'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { RichTextContent } from '@/shared/components/richtext/RichTextContent'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import {
  displayMoney,
  formatMinorUnits,
  moneyToMinorUnits,
  type IMoney,
} from '@/shared/utils/money'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

const KPI = 'rounded-[14px] border border-border bg-card p-4 shadow-sm'
const KPI_LABEL = 'text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'
const KPI_VALUE = 'mt-1 text-[22px] font-semibold tracking-[-0.01em] tabular-nums'

type ProjectAction = 'activate' | 'close' | 'cancel'

// ─── pure helpers ────────────────────────────────────────────────────────────

function actionTitle(action: ProjectAction | null): string {
  if (action === 'activate') return 'Activar proyecto'
  if (action === 'close') return 'Cerrar proyecto'
  return 'Cancelar proyecto'
}

function actionDescription(action: ProjectAction | null): string {
  if (action === 'activate')
    return 'Se generarán las aportaciones para las unidades del alcance. Si el proyecto es obligatorio, también se crearán los cargos.'
  if (action === 'close')
    return 'El proyecto se marcará como ejecutado y dejará de aceptar aportaciones.'
  return 'El proyecto quedará cancelado. Esta acción queda en auditoría.'
}

function actionConfirmLabel(action: ProjectAction | null): string {
  if (action === 'activate') return 'Activar'
  if (action === 'close') return 'Cerrar'
  return 'Cancelar proyecto'
}

function successMessage(action: ProjectAction): string {
  if (action === 'activate') return 'Proyecto activado — se generaron las aportaciones'
  if (action === 'close') return 'Proyecto cerrado'
  return 'Proyecto cancelado'
}

function runProjectAction(id: string, action: ProjectAction) {
  if (action === 'activate') return CommunityProjectResource.activate(id)
  if (action === 'close') return CommunityProjectResource.close(id)
  return CommunityProjectResource.cancelProject(id)
}

function toastActionError(error: unknown) {
  toast.error(
    error instanceof ApiError
      ? formatApiErrorMessage(error as ApiError)
      : 'No se pudo completar la acción'
  )
}

function buildIsContributable(
  projectData: ICommunityProject | undefined,
  isVoluntary: boolean
): boolean {
  return (
    isVoluntary &&
    (projectData?.community_project_status_id === COMMUNITY_PROJECT_STATUS.ACTIVE ||
      projectData?.community_project_status_id === COMMUNITY_PROJECT_STATUS.FULLY_FUNDED)
  )
}

// ─── sub-components ──────────────────────────────────────────────────────────

interface ProjectHeaderProps {
  project: ICommunityProject
  isDraft: boolean
  isActive: boolean
  canRemind: boolean
  onAction: (_action: ProjectAction) => void
  onSendReminder: () => void
  onEdit: () => void
}

function ProjectHeader({
  project,
  isDraft,
  isActive,
  canRemind,
  onAction,
  onSendReminder,
  onEdit,
}: ProjectHeaderProps) {
  return (
    <div className='flex flex-wrap items-start justify-between gap-4'>
      <div>
        <Link
          to='/community-project'
          className='mb-1 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground'
        >
          <ArrowLeft className='size-3.5' />
          Proyectos
        </Link>
        <h1 className='flex items-center gap-3 text-[26px] font-semibold tracking-[-0.02em]'>
          {project.title}
          <StatusPill tone={projectTone(project)}>{project.status?.name ?? '—'}</StatusPill>
        </h1>
        <p className='mt-1 text-[13.5px] text-[var(--ink-3)]'>
          {project.category?.name}
          {project.participation_type?.name ? ` · ${project.participation_type.name}` : ''}
          {project.due_date ? ` · límite ${displayFormatDate(project.due_date, 'mediumDate')}` : ''}
        </p>
      </div>
      <PermissionGuard permission='update.community_project'>
        <div className='flex flex-wrap gap-2'>
          <Button variant='outline' className='rounded-full' onClick={onEdit}>
            Editar
          </Button>
          {isDraft && (
            <Button className='rounded-full' onClick={() => onAction('activate')}>
              Activar proyecto
            </Button>
          )}
          {canRemind && (
            <Button variant='outline' className='rounded-full' onClick={onSendReminder}>
              Enviar recordatorio
            </Button>
          )}
          {isActive && (
            <Button variant='outline' className='rounded-full' onClick={() => onAction('close')}>
              Cerrar
            </Button>
          )}
          {(isDraft || isActive) && (
            <Button
              variant='outline'
              className='rounded-full text-destructive hover:text-destructive'
              onClick={() => onAction('cancel')}
            >
              Cancelar
            </Button>
          )}
        </div>
      </PermissionGuard>
    </div>
  )
}

interface SummaryData {
  raised?: IMoney
  executed?: IMoney
  available?: IMoney
}

interface FinancialKpisProps {
  collected: number
  goal: number
  progressPct: number
  summary: SummaryData | undefined
}

function FinancialKpis({ collected, goal, progressPct, summary }: FinancialKpisProps) {
  const available = moneyToMinorUnits(summary?.available)
  return (
    <div className='grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
      <div className={KPI}>
        <div className={KPI_LABEL}>Recaudado</div>
        <div className={`${KPI_VALUE} text-[var(--green)]`}>{formatMinorUnits(collected)}</div>
        {goal > 0 && (
          <>
            <div className='mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--line-2)]'>
              <i
                className='block h-full rounded-full bg-[var(--green)]'
                style={{ width: `${progressPct}%` }}
              />
            </div>
            <div className='mt-1 text-[12px] text-[var(--ink-3)]'>{progressPct}% de la meta</div>
          </>
        )}
      </div>
      <div className={KPI}>
        <div className={KPI_LABEL}>Meta</div>
        <div className={KPI_VALUE}>{goal > 0 ? formatMinorUnits(goal) : 'Libre'}</div>
      </div>
      <div className={KPI}>
        <div className={KPI_LABEL}>Ejecutado</div>
        <div className={KPI_VALUE}>{displayMoney(summary?.executed)}</div>
        <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>egresos vinculados al proyecto</div>
      </div>
      <div className={KPI}>
        <div className={KPI_LABEL}>Disponible</div>
        <div className={`${KPI_VALUE} ${available >= 0 ? '' : 'text-[#C0533C]'}`}>
          {formatMinorUnits(available)}
        </div>
      </div>
    </div>
  )
}

// ─── data hook ───────────────────────────────────────────────────────────────

function calcProgressPct(collected: number, goal: number): number {
  return goal > 0 ? Math.min(100, Math.round((collected / goal) * 100)) : 0
}

function useProjectData(id: string) {
  const {
    entity: project,
    isLoading,
    isError,
    isSuccess,
    notFound,
    refetch,
  } = useEntityDetail<ICommunityProject>({
    queryKey: ['project', 'detail', id],
    queryFn: () =>
      CommunityProjectResource.show(id, { include: 'status,category,participationType,media' }),
    enabled: Boolean(id),
  })

  const { data: summary } = useQuery({
    queryKey: ['project', 'summary', id],
    queryFn: () => CommunityProjectResource.summary(id),
    enabled: Boolean(id) && isSuccess,
  })

  return { project, isLoading, summary, isError, notFound, refetch }
}

// ─── main component ──────────────────────────────────────────────────────────

/**
 * Detalle de proyecto comunitario (§11.5): resumen recaudado/ejecutado/disponible,
 * avance vs meta, descripción, documentos y aportaciones por unidad (lista compartida).
 */
export const CommunityProjectDetailPage = () => {
  const { id = '' } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const [confirmAction, setConfirmAction] = useState<ProjectAction | null>(null)
  const [reminderOpen, setReminderOpen] = useState(false)
  const [editOpen, setEditOpen] = useState(false)

  const { project, isLoading, summary, isError, notFound, refetch } = useProjectData(id)
  const canManageProject = usePermissions('update.community_project')

  const realIsVoluntary = project?.participation_type_id === PARTICIPATION_TYPE.VOLUNTARY
  const isContributable = buildIsContributable(project, realIsVoluntary)

  const actionMutation = useMutation({
    mutationFn: (action: ProjectAction) => runProjectAction(id, action),
    onSuccess: (_result, action) => {
      toast.success(successMessage(action))
      setConfirmAction(null)
      void queryClient.invalidateQueries({ queryKey: ['project'] })
    },
    onError: toastActionError,
  })

  const reminderMutation = useMutation({
    mutationFn: () => CommunityProjectResource.sendReminder(id),
    onSuccess: (result) => {
      toast.success(`Recordatorio enviado a ${result.sent} unidad(es)`)
      setReminderOpen(false)
    },
    onError: toastActionError,
  })

  const handleConfirm = useCallback(() => {
    if (confirmAction) actionMutation.mutate(confirmAction)
  }, [confirmAction, actionMutation])

  if (isLoading) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando proyecto…</p>
  }

  if (isError || !project) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Proyecto no encontrado' : undefined}
        description={notFound ? 'El proyecto no existe o no tienes acceso a él.' : undefined}
        backTo='/community-project'
        backLabel='Volver a proyectos'
        onRetry={notFound ? undefined : refetch}
      />
    )
  }

  const isDraft = project.community_project_status_id === COMMUNITY_PROJECT_STATUS.DRAFT
  const isActive = project.community_project_status_id === COMMUNITY_PROJECT_STATUS.ACTIVE
  const goal = moneyToMinorUnits(project.goal_amount ?? 0)
  const collected = moneyToMinorUnits(summary?.raised)
  const progressPct = calcProgressPct(collected, goal)
  const confirmVariant = confirmAction === 'cancel' ? 'delete' : 'default'
  const hasDescription = Boolean(project.description_html || project.description)

  return (
    <div className='space-y-5'>
      <ProjectHeader
        project={project}
        isDraft={isDraft}
        isActive={isActive}
        canRemind={isContributable}
        onAction={setConfirmAction}
        onSendReminder={() => setReminderOpen(true)}
        onEdit={() => setEditOpen(true)}
      />

      <FinancialKpis
        collected={collected}
        goal={goal}
        progressPct={progressPct}
        summary={summary}
      />

      {hasDescription && (
        <section className={KPI}>
          <h3 className='mb-2 text-sm font-semibold'>Descripción</h3>
          <RichTextContent
            html={project.description_html}
            fallbackText={project.description}
            className='text-[14px] text-foreground'
          />
        </section>
      )}

      <ProjectDocuments projectId={id} media={project.media ?? []} canManage={canManageProject} />

      <ContributionsList projectId={id} project={project} />

      {/* Comentarios ocultos temporalmente por decisión de producto (2026-07) — reactivar descomentando. */}
      {/* <ProjectComments projectId={id} /> */}

      <ConfirmDialog
        isOpen={Boolean(confirmAction)}
        variant={confirmVariant}
        title={actionTitle(confirmAction)}
        description={actionDescription(confirmAction)}
        confirmLabel={actionConfirmLabel(confirmAction)}
        isLoading={actionMutation.isPending}
        onConfirm={handleConfirm}
        onCancel={() => setConfirmAction(null)}
      />

      <ConfirmDialog
        isOpen={reminderOpen}
        title='Enviar recordatorio'
        description='Se notificará a las unidades con aportación pendiente.'
        confirmLabel='Enviar'
        isLoading={reminderMutation.isPending}
        onConfirm={() => reminderMutation.mutate()}
        onCancel={() => setReminderOpen(false)}
      />

      <ProjectEditModal isOpen={editOpen} onClose={() => setEditOpen(false)} project={project} />
    </div>
  )
}
