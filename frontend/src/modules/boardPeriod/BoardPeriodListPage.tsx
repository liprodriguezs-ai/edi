import { Button } from '@/components/ui/button'
import {
  BOARD_PERIOD_STATUS,
  REQUIRED_BOARD_ROLES_MESSAGE,
  findUncoveredRequiredRole,
  type IBoardMember,
  type IBoardPeriod,
} from '@/modules/boardPeriod/schema'
import { BoardPeriodResource } from '@/modules/boardPeriod/services/BoardPeriodResource'
import { BoardMemberResource } from '@/modules/boardPeriod/services/BoardMemberResource'
import { useBoardPeriodStore } from '@/modules/boardPeriod/store/boardPeriodStore'
import { StartPeriodModal } from '@/modules/boardPeriod/components/StartPeriodModal'
import { BoardMemberModal } from '@/modules/boardPeriod/components/BoardMemberModal'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { usePermissions, useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayInitials } from '@/shared/utils/displayInitials'
import { useMutation } from '@tanstack/react-query'
import { FileText, Paperclip, Pencil, Plus, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { useShallow } from 'zustand/react/shallow'

/**
 * Mesa Directiva (§7.3): periodos con sus miembros. Iniciar un periodo asigna
 * los roles Spatie; cerrarlo los revoca. El acta de asamblea se adjunta al periodo.
 */
export const BoardPeriodListPage = () => {
  const [startOpen, setStartOpen] = useState(false)
  const [closeTarget, setCloseTarget] = useState<IBoardPeriod | null>(null)
  const [actaTarget, setActaTarget] = useState<IBoardPeriod | null>(null)
  const [downloadingId, setDownloadingId] = useState<string | null>(null)
  const [memberModalPeriod, setMemberModalPeriod] = useState<IBoardPeriod | null>(null)
  const [memberEditing, setMemberEditing] = useState<IBoardMember | null>(null)
  const [removeMemberTarget, setRemoveMemberTarget] = useState<IBoardMember | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const downloadActa = async (period: IBoardPeriod) => {
    const acta = period.media?.find((m) => m.collection_name === 'acta')
    if (!acta) return
    setDownloadingId(period.id)
    try {
      await downloadMediaWithSignedUrl({
        mediaId: acta.id,
        fileName: acta.file_name,
        errorMessage: 'No se pudo descargar el acta',
      })
    } finally {
      setDownloadingId(null)
    }
  }

  const canManage = usePermissions('update.board_period')
  const memberPerms = useMultiplePermissions({
    canCreateMember: 'create.board_member',
    canEditMember: 'update.board_member',
    canDeleteMember: 'delete.board_member',
  })

  const openAddMember = (period: IBoardPeriod) => {
    setMemberEditing(null)
    setMemberModalPeriod(period)
  }

  const openEditMember = (period: IBoardPeriod, member: IBoardMember) => {
    setMemberEditing(member)
    setMemberModalPeriod(period)
  }

  const closeMemberModal = () => {
    setMemberModalPeriod(null)
    setMemberEditing(null)
  }

  // §7.3.2: quitar al último ocupante de un cargo obligatorio dejaría a la mesa
  // sin Presidente/Tesorero/Secretario — se bloquea antes de abrir el confirm.
  const handleRemoveMemberClick = (period: IBoardPeriod, member: IBoardMember) => {
    if (findUncoveredRequiredRole(period.members ?? [], member.id) !== null) {
      toast.error(REQUIRED_BOARD_ROLES_MESSAGE)
      return
    }
    setRemoveMemberTarget(member)
  }

  const { onPageChanged, onSearch, paramsSearch } = useBaseList<IBoardPeriod>({
    store: useBoardPeriodStore,
    resource: BoardPeriodResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'start_date', direction: 'desc' }],
      includes: [
        { relation: 'board_period_status' },
        { relation: 'members' },
        { relation: 'members.board_role' },
        { relation: 'members.user' },
        { relation: 'media' },
      ],
    },
  })
  const { entities, pagination, isLoading } = useBoardPeriodStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  const refresh = () => onSearch({ ...paramsSearch })

  const closeMutation = useMutation({
    mutationFn: (period: IBoardPeriod) => BoardPeriodResource.closePeriod(period.id),
    onSuccess: () => {
      toast.success('Periodo cerrado — los roles de mesa fueron revocados')
      setCloseTarget(null)
      refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo cerrar el periodo'
      ),
  })

  const actaMutation = useMutation({
    mutationFn: ({ period, file }: { period: IBoardPeriod; file: File }) =>
      BoardPeriodResource.uploadActa(period.id, file),
    onSuccess: () => {
      toast.success('Acta adjuntada al periodo')
      setActaTarget(null)
      refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo subir el acta'
      ),
  })

  const removeMemberMutation = useMutation({
    mutationFn: (member: IBoardMember) => BoardMemberResource.remove(member.id),
    onSuccess: () => {
      toast.success('Miembro eliminado — se revocó su rol de mesa')
      setRemoveMemberTarget(null)
      refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo eliminar el miembro'
      ),
  })

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Mesa Directiva'
        description='Periodos de la mesa y sus cargos'
        actions={
          <PermissionGuard permission='create.board_period'>
            <Button className='rounded-full' onClick={() => setStartOpen(true)}>
              <Plus className='size-4' />
              Iniciar nuevo periodo
            </Button>
          </PermissionGuard>
        }
      />

      {isLoading ? (
        <TableLoadingState />
      ) : entities.length === 0 ? (
        <TableEmptyState
          title='Aún no hay periodos de mesa directiva'
          description='Inicia el primero con los cargos electos en asamblea.'
        />
      ) : (
        <>
          <div className='space-y-4'>
            {entities.map((period) => {
              const isCurrent = period.board_period_status_id === BOARD_PERIOD_STATUS.CURRENT
              return (
                <div
                  key={period.id}
                  className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'
                >
                  <div className='flex flex-wrap items-center justify-between gap-3 border-b border-[var(--line-2)] px-5 py-4'>
                    <div>
                      <h2 className='flex items-center gap-3 text-[17px] font-semibold'>
                        {period.name}
                        <StatusPill tone={isCurrent ? 'g' : 'r'}>
                          {period.board_period_status?.name ?? (isCurrent ? 'Vigente' : 'Cerrado')}
                        </StatusPill>
                      </h2>
                      <p className='mt-0.5 text-[12.5px] text-[var(--ink-3)]'>
                        {displayFormatDate(period.start_date, 'mediumDate')} —{' '}
                        {displayFormatDate(period.end_date, 'mediumDate')}
                      </p>
                    </div>
                    <div className='flex gap-2'>
                      {period.media?.some((m) => m.collection_name === 'acta') && (
                        <Button
                          variant='outline'
                          size='sm'
                          className='rounded-full'
                          onClick={() => void downloadActa(period)}
                          disabled={downloadingId === period.id}
                        >
                          <FileText className='size-3.5' />
                          Acta
                        </Button>
                      )}
                      {isCurrent && memberPerms.canCreateMember && (
                        <Button
                          variant='outline'
                          size='sm'
                          className='rounded-full'
                          onClick={() => openAddMember(period)}
                        >
                          <Plus className='size-3.5' />
                          Agregar miembro
                        </Button>
                      )}
                      {canManage && isCurrent && (
                        <>
                          <Button
                            variant='outline'
                            size='sm'
                            className='rounded-full'
                            onClick={() => {
                              setActaTarget(period)
                              fileRef.current?.click()
                            }}
                          >
                            <Paperclip className='size-3.5' />
                            Subir acta
                          </Button>
                          <Button
                            variant='outline'
                            size='sm'
                            className='rounded-full text-destructive hover:text-destructive'
                            onClick={() => setCloseTarget(period)}
                          >
                            Cerrar periodo
                          </Button>
                        </>
                      )}
                    </div>
                  </div>

                  <div className='grid grid-cols-1 gap-0 sm:grid-cols-2 lg:grid-cols-3'>
                    {(period.members ?? []).map((member) => (
                      <div
                        key={member.id}
                        className='flex items-center gap-3 border-b border-[var(--line-2)] px-5 py-3 last:border-b-0 sm:border-b-0'
                      >
                        <span className='grid size-9 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[12px] font-semibold text-[var(--green)]'>
                          {displayInitials(member.user?.name ?? '?')}
                        </span>
                        <div className='min-w-0 flex-1'>
                          <p className='truncate text-[13.5px] font-semibold'>
                            {member.user?.name ?? '—'}
                          </p>
                          <p className='text-[12px] text-[var(--ink-3)]'>
                            {member.board_role?.name ?? '—'}
                          </p>
                        </div>
                        {isCurrent &&
                          (memberPerms.canEditMember || memberPerms.canDeleteMember) && (
                            <div className='flex flex-none items-center gap-1'>
                              {memberPerms.canEditMember && (
                                <Button
                                  variant='ghost'
                                  size='icon'
                                  className='size-8'
                                  aria-label='Editar miembro'
                                  onClick={() => openEditMember(period, member)}
                                >
                                  <Pencil className='size-4' />
                                </Button>
                              )}
                              {memberPerms.canDeleteMember && (
                                <Button
                                  variant='ghost'
                                  size='icon'
                                  className='size-8 text-destructive hover:text-destructive'
                                  aria-label='Quitar miembro'
                                  onClick={() => handleRemoveMemberClick(period, member)}
                                >
                                  <Trash2 className='size-4' />
                                </Button>
                              )}
                            </div>
                          )}
                      </div>
                    ))}
                    {(period.members ?? []).length === 0 && (
                      <p className='px-5 py-4 text-[13px] text-[var(--ink-3)]'>
                        Sin miembros registrados.
                      </p>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
          <Pagination pagination={pagination} onPageChanged={onPageChanged} />
        </>
      )}

      <input
        ref={fileRef}
        type='file'
        accept='.pdf,image/*'
        className='hidden'
        onChange={(e) => {
          const file = e.target.files?.[0]
          if (file && actaTarget) {
            actaMutation.mutate({ period: actaTarget, file })
          }
          e.target.value = ''
        }}
      />

      <StartPeriodModal
        isOpen={startOpen}
        onClose={() => setStartOpen(false)}
        onCreated={refresh}
      />

      {memberModalPeriod && (
        <BoardMemberModal
          isOpen={Boolean(memberModalPeriod)}
          period={memberModalPeriod}
          member={memberEditing}
          onClose={closeMemberModal}
          onSaved={refresh}
        />
      )}

      <ConfirmDialog
        isOpen={Boolean(closeTarget)}
        variant='delete'
        title='Cerrar periodo'
        description={`Se cerrará «${closeTarget?.name}» y se revocarán los roles de mesa de todos sus miembros. Esta acción queda en auditoría.`}
        confirmLabel='Cerrar periodo'
        isLoading={closeMutation.isPending}
        onConfirm={() => closeTarget && closeMutation.mutate(closeTarget)}
        onCancel={() => setCloseTarget(null)}
      />

      <ConfirmDialog
        isOpen={Boolean(removeMemberTarget)}
        variant='delete'
        title='Quitar miembro'
        description={`Se quitará a «${removeMemberTarget?.user?.name ?? 'este miembro'}» de la mesa y se revocará su rol. Esta acción queda en auditoría.`}
        confirmLabel='Quitar miembro'
        isLoading={removeMemberMutation.isPending}
        onConfirm={() => removeMemberTarget && removeMemberMutation.mutate(removeMemberTarget)}
        onCancel={() => setRemoveMemberTarget(null)}
      />
    </div>
  )
}
