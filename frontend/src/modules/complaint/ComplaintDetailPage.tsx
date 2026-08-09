import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { IComplaint } from '@/modules/complaint/schema'
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'
import { ComplaintHistorySection } from '@/modules/complaint/components/ComplaintHistorySection'
import { ComplaintPriorityChip } from '@/modules/complaint/components/ComplaintPriorityChip'
import { ComplaintStatusBadge } from '@/modules/complaint/components/ComplaintStatusBadge'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Send } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Skeleton } from 'boneyard-js/react'

// ─── FSM transition matrix — mirrors ComplaintService::isValidTransition ──────
const TRANSITIONS: Record<string, string[]> = {
  open: ['in_review', 'resolved', 'dismissed'],
  in_review: ['open', 'resolved', 'dismissed'],
  resolved: ['open', 'in_review'],
  dismissed: ['open', 'in_review'],
}

// ─── Detail field ─────────────────────────────────────────────────────────────

function DetailField({ label, value }: { label: string; value: React.ReactNode }) {
  if (!value && value !== 0) return null
  return (
    <div className='grid grid-cols-[180px_1fr] gap-2 text-[13px]'>
      <dt className='text-muted-foreground'>{label}</dt>
      <dd className='font-medium text-foreground'>{value}</dd>
    </div>
  )
}

// ─── Aging chip ────────────────────────────────────────────────────────────────

function agingLabel(complaint: IComplaint): string {
  const start = new Date(complaint.created_at ?? Date.now()).getTime()
  const end = complaint.resolved_at ? new Date(complaint.resolved_at).getTime() : Date.now()
  const days = Math.max(0, Math.floor((end - start) / 86_400_000))
  return complaint.resolved_at ? `Resuelta en ${days} días` : `${days} días abierta`
}

/**
 * Thresholds derived from the tenant's complaint.resolution_days_target
 * (module-config §12, review W9): amber past half the target, red past the
 * full target. Falls back to the manifest default (15) if the backend hasn't
 * appended it yet.
 */
function agingClass(complaint: IComplaint): string {
  if (complaint.resolved_at) return 'text-muted-foreground'
  const target = complaint.resolution_days_target ?? 15
  const days = Math.floor(
    (Date.now() - new Date(complaint.created_at ?? Date.now()).getTime()) / 86_400_000
  )
  if (days > target) return 'text-red-600 font-semibold'
  if (days > Math.ceil(target / 2)) return 'text-amber-600'
  return 'text-muted-foreground'
}

// ─── Evidence gallery ──────────────────────────────────────────────────────────

function EvidenceSection({ complaint }: { complaint: IComplaint }) {
  const items = complaint.media?.filter((m) => m.collection_name === 'evidence') ?? []
  if (items.length === 0) return null

  return (
    <div className='rounded-[12px] border border-border bg-card p-5 shadow-sm space-y-3'>
      <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
        Evidencia
      </h2>
      <div className='grid grid-cols-2 gap-3 sm:grid-cols-3'>
        {items.map((item) =>
          item.mime_type === 'application/pdf' ? (
            <a
              key={item.id}
              href={item.original_url}
              target='_blank'
              rel='noopener noreferrer'
              className='flex items-center gap-2 text-[13px] text-blue-600 underline underline-offset-2 hover:text-blue-800'
            >
              {item.file_name ?? 'Documento de evidencia'}
            </a>
          ) : (
            <div key={item.id} className='rounded-[12px] border border-border overflow-hidden'>
              <img
                src={item.original_url}
                alt='Evidencia de la incidencia'
                className='h-40 w-full object-cover bg-[var(--surface-2)]'
                loading='lazy'
              />
            </div>
          )
        )}
      </div>
    </div>
  )
}

// ─── Status transition control ─────────────────────────────────────────────────

interface TransitionControlProps {
  complaint: IComplaint
  onChanged: () => void
}

function TransitionControl({ complaint, onChanged }: TransitionControlProps) {
  const [target, setTarget] = useState<string | undefined>(undefined)

  const {
    catalogs: statusCatalogs,
    enable: enableStatuses,
    isLoading: loadingStatuses,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.COMPLAINT_STATUS]: { _sort: 'name' } }), []))

  const currentKey = complaint.status?.key ?? ''
  const allowedKeys = TRANSITIONS[currentKey] ?? []
  const options = (statusCatalogs[CatalogType.COMPLAINT_STATUS] ?? []).filter((s) =>
    allowedKeys.includes((s.key as string | undefined) ?? '')
  )

  const mutation = useMutation({
    mutationFn: (statusId: string) => ComplaintResource.transition(complaint.id, statusId),
    onSuccess: () => {
      toast.success('Estado actualizado')
      setTarget(undefined)
      onChanged()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error).title
          : 'No se pudo cambiar el estado'
      ),
  })

  if (allowedKeys.length === 0) return null

  return (
    <div className='flex flex-wrap items-center gap-2'>
      <Select
        value={target}
        onValueChange={setTarget}
        onOpenChange={(open) => {
          if (open) enableStatuses()
        }}
      >
        <SelectTrigger className='w-[220px]'>
          <SelectValue placeholder={loadingStatuses ? 'Cargando…' : 'Cambiar estado a…'} />
        </SelectTrigger>
        <SelectContent>
          {options.map((s) => (
            <SelectItem key={s.id} value={s.id}>
              {s.name as string}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Button
        size='sm'
        disabled={!target || mutation.isPending}
        onClick={() => target && mutation.mutate(target)}
      >
        {mutation.isPending ? 'Aplicando…' : 'Aplicar'}
      </Button>
    </div>
  )
}

// ─── Assignment control ─────────────────────────────────────────────────────────

interface AssignmentControlProps {
  complaint: IComplaint
  onChanged: () => void
}

function AssignmentControl({ complaint, onChanged }: AssignmentControlProps) {
  const [assigneeId, setAssigneeId] = useState<string | null>(null)
  const [shouldLoadAssignees, setShouldLoadAssignees] = useState(false)

  // Eligible assignees (review W6): configured managers minus the unit-linkage
  // exclusion set, so the picker never offers someone assign() would reject.
  // Lazy on menu open, matching the load-on-open catalog convention.
  const { data: eligibleAssignees = [] } = useQuery({
    queryKey: ['complaint-eligible-assignees', complaint.id],
    queryFn: () => ComplaintResource.eligibleAssignees(complaint.id),
    enabled: shouldLoadAssignees,
  })

  const mutation = useMutation({
    mutationFn: (userId: string) => ComplaintResource.assign(complaint.id, userId),
    onSuccess: () => {
      toast.success('Incidencia asignada')
      setAssigneeId(null)
      onChanged()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error).title : 'No se pudo asignar'
      ),
  })

  return (
    <div className='flex flex-wrap items-center gap-2'>
      <div className='w-[240px]'>
        <SearchSelect
          value={assigneeId}
          onChange={(v) => setAssigneeId(v as string | null)}
          options={eligibleAssignees}
          placeholder='Buscar responsable…'
          isClearable
          onMenuOpen={() => setShouldLoadAssignees(true)}
        />
      </div>
      <Button
        size='sm'
        disabled={!assigneeId || mutation.isPending}
        onClick={() => assigneeId && mutation.mutate(assigneeId)}
      >
        {mutation.isPending ? 'Asignando…' : complaint.assignee ? 'Reasignar' : 'Asignar'}
      </Button>
    </div>
  )
}

// ─── Message thread ──────────────────────────────────────────────────────────

function MessageThread({ complaintId }: { complaintId: string }) {
  const queryClient = useQueryClient()
  const attachmentRef = useRef<HTMLInputElement>(null)
  const [body, setBody] = useState('')
  const [attachment, setAttachment] = useState<File | null>(null)

  const {
    data: messages,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ['complaint-messages', complaintId],
    queryFn: () => ComplaintResource.messages(complaintId),
  })

  const mutation = useMutation({
    mutationFn: () => {
      const form = new FormData()
      form.append('body', body)
      if (attachment) form.append('attachment', attachment)
      return ComplaintResource.postMessage(complaintId, form)
    },
    onSuccess: () => {
      setBody('')
      setAttachment(null)
      if (attachmentRef.current) attachmentRef.current.value = ''
      void queryClient.invalidateQueries({ queryKey: ['complaint-messages', complaintId] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error).title
          : 'No se pudo enviar el mensaje'
      ),
  })

  return (
    <div className='rounded-[12px] border border-border bg-card p-5 shadow-sm space-y-4'>
      <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
        Seguimiento
      </h2>

      {isLoading ? (
        <p className='text-[13px] text-muted-foreground'>Cargando mensajes…</p>
      ) : isError ? (
        <p className='text-[13px] text-destructive'>No se pudieron cargar los mensajes.</p>
      ) : messages && messages.length > 0 ? (
        <div className='space-y-3'>
          {messages.map((m) => (
            <div key={m.id} className='rounded-[10px] bg-[var(--surface-2)] p-3 space-y-1'>
              <div className='flex items-center justify-between text-[11.5px] text-muted-foreground'>
                <span className='font-medium text-foreground'>{m.author?.name ?? 'Usuario'}</span>
                <span>{displayFormatDate(m.created_at)}</span>
              </div>
              <p className='text-[13px] whitespace-pre-wrap'>{m.body}</p>
              {m.media
                ?.filter((media) => media.collection_name === 'attachment')
                .map((media) => (
                  <a
                    key={media.id}
                    href={media.original_url}
                    target='_blank'
                    rel='noopener noreferrer'
                    className='block text-[12px] text-blue-600 underline underline-offset-2 hover:text-blue-800'
                  >
                    {media.file_name ?? 'Adjunto'}
                  </a>
                ))}
            </div>
          ))}
        </div>
      ) : (
        <p className='text-[13px] text-muted-foreground'>Sin mensajes todavía.</p>
      )}

      <Separator />

      <div className='space-y-2'>
        <Textarea
          rows={2}
          placeholder='Escribe un mensaje de seguimiento…'
          value={body}
          onChange={(e) => setBody(e.target.value)}
          disabled={mutation.isPending}
          className='resize-none'
        />
        <div className='flex flex-wrap items-center justify-between gap-2'>
          <input
            ref={attachmentRef}
            type='file'
            accept='image/jpeg,image/png,image/webp,application/pdf'
            className='block text-[12.5px] text-muted-foreground file:mr-2 file:rounded-full file:border-0 file:bg-[var(--surface-2)] file:px-2.5 file:py-1 file:text-xs file:font-medium'
            onChange={(e) => setAttachment(e.target.files?.[0] ?? null)}
          />
          <Button
            size='sm'
            disabled={!body.trim() || mutation.isPending}
            onClick={() => mutation.mutate()}
            className='flex items-center gap-1.5'
          >
            <Send className='size-3.5' />
            {mutation.isPending ? 'Enviando…' : 'Enviar'}
          </Button>
        </div>
      </div>
    </div>
  )
}

// ─── Main page ────────────────────────────────────────────────────────────────

export function ComplaintDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const {
    entity: complaint,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IComplaint>({
    queryKey: ['complaint', id],
    queryFn: () =>
      ComplaintResource.show(id!, {
        include: 'category,priority,status,reporter,assignee,against_unit,media',
      } as Record<string, unknown>),
    enabled: Boolean(id),
  })

  useEffect(() => {
    if (isError && !notFound) toast.error('No se pudo cargar el detalle de la incidencia')
  }, [isError, notFound])

  const handleChanged = () => refetch()

  return (
    <div className='space-y-6'>
      <div>
        <Button
          variant='ghost'
          size='sm'
          className='-ml-2 flex items-center gap-1.5 text-muted-foreground'
          onClick={() => navigate(-1)}
        >
          <ArrowLeft className='size-4' />
          Volver
        </Button>
      </div>

      <Skeleton name='complaint-detail' loading={isLoading}>
        {isError || !complaint ? (
          <EntityNotFound
            variant={notFound ? 'notFound' : 'error'}
            title={notFound ? 'Incidencia no encontrada' : undefined}
            backTo='/complaint'
            backLabel='Volver a incidencias'
            onRetry={notFound ? undefined : refetch}
          />
        ) : (
          <div className='space-y-6'>
            {/* Header */}
            <div className='flex flex-wrap items-start justify-between gap-4'>
              <div>
                <PageHeader
                  title={`${complaint.case_number} — ${complaint.title}`}
                  description={`Registrada ${complaint.created_at ? displayFormatDate(complaint.created_at) : '—'} por ${complaint.reporter?.name ?? '—'}`}
                />
                <div className='mt-2 flex flex-wrap items-center gap-2'>
                  <ComplaintStatusBadge
                    statusKey={complaint.status?.key ?? null}
                    statusName={complaint.status?.name ?? null}
                    statusColor={complaint.status?.color ?? null}
                  />
                  <ComplaintPriorityChip
                    name={complaint.priority?.name}
                    color={complaint.priority?.color}
                  />
                  <span className={`text-[12.5px] ${agingClass(complaint)}`}>
                    {agingLabel(complaint)}
                  </span>
                </div>
              </div>
            </div>

            {/* Status transition (manager-gated) */}
            <PermissionGuard permission='update.complaint'>
              <div className='rounded-[12px] border border-border bg-card p-5 shadow-sm space-y-3'>
                <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
                  Cambiar estado
                </h2>
                <TransitionControl complaint={complaint} onChanged={handleChanged} />
              </div>
            </PermissionGuard>

            {/* Subject */}
            <div className='rounded-[12px] border border-border bg-card p-5 shadow-sm space-y-3'>
              <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
                Incidencia
              </h2>
              <dl className='space-y-2'>
                <DetailField label='Categoría' value={complaint.category?.name ?? '—'} />
                <DetailField
                  label='Unidad señalada'
                  value={
                    complaint.against_unit?.full_identifier ??
                    complaint.against_unit?.number ??
                    'Sin unidad específica'
                  }
                />
                <DetailField label='Descripción' value={complaint.against_description} />
              </dl>
            </div>

            {/* Evidence */}
            <EvidenceSection complaint={complaint} />

            {/* Assignment (manager-gated) */}
            <PermissionGuard permission='update.complaint'>
              <div className='rounded-[12px] border border-border bg-card p-5 shadow-sm space-y-3'>
                <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
                  Asignación
                </h2>
                <dl>
                  <DetailField
                    label='Responsable actual'
                    value={complaint.assignee?.name ?? 'Sin asignar'}
                  />
                </dl>
                <AssignmentControl complaint={complaint} onChanged={handleChanged} />
              </div>
            </PermissionGuard>

            {/* Message thread */}
            <MessageThread complaintId={complaint.id} />

            {/* Change history (audit trail) */}
            <ComplaintHistorySection complaintId={complaint.id} />
          </div>
        )}
      </Skeleton>
    </div>
  )
}
