import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { ANNOUNCEMENT_STATUS, type IAnnouncement } from '@/modules/announcement/schema'
import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import { useAnnouncementStore } from '@/modules/announcement/store/announcementStore'
import { StatusPill, type PillTone } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation } from '@tanstack/react-query'
import { Archive, Megaphone, Pencil, Plus } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

function announcementTone(announcement: IAnnouncement): PillTone {
  switch (announcement.announcement_status_id) {
    case ANNOUNCEMENT_STATUS.PUBLISHED:
      return 'g'
    case ANNOUNCEMENT_STATUS.DRAFT:
      return 'y'
    default:
      return 'r'
  }
}

export const AnnouncementListPage = () => {
  const canUpdate = usePermissions('update.announcement')
  const navigate = useNavigate()

  const { onPageChanged, onSearch, onReset, paramsSearch } = useBaseList<IAnnouncement>({
    store: useAnnouncementStore,
    resource: AnnouncementResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        { relation: 'type' },
        { relation: 'priority' },
        { relation: 'status' },
        { relation: 'media' },
      ],
      // Request only the COUNT of reads (→ reads_count), not the full hasMany. A popular
      // aviso in a cluster with hundreds of residents would otherwise load hundreds of
      // AnnouncementRead rows per row just to render one integer.
      aggregates: [{ relation: 'reads', type: 'count' }],
    },
  })
  const { entities, pagination, isLoading } = useAnnouncementStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  const refresh = () => onSearch({ ...paramsSearch })

  const publishMutation = useMutation({
    mutationFn: (announcement: IAnnouncement) => AnnouncementResource.publish(announcement.id),
    onSuccess: () => {
      toast.success('Aviso publicado — se notificó por los canales elegidos')
      refresh()
    },
    onError: (error) =>
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo publicar'),
  })

  const archiveMutation = useMutation({
    mutationFn: (announcement: IAnnouncement) => AnnouncementResource.archive(announcement.id),
    onSuccess: () => {
      toast.success('Aviso archivado')
      refresh()
    },
    onError: (error) =>
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo archivar'),
  })

  const openAnnouncement = (announcement: IAnnouncement) =>
    navigate(`/announcement/${announcement.id}`)

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Avisos'
        description='Comunicados del condominio a los residentes'
        actions={
          <PermissionGuard permission='create.announcement'>
            <Button className='rounded-full' onClick={() => navigate('/announcement/new')}>
              <Plus className='size-4' />
              Nuevo aviso
            </Button>
          </PermissionGuard>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar avisos…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Sin avisos'
            description='Crea el primero para comunicarte con los residentes.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IAnnouncement>
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Aviso</TableHead>
                    <TableHead scope='col'>Tipo</TableHead>
                    <TableHead scope='col' className='hidden lg:table-cell'>
                      Prioridad
                    </TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Lecturas
                    </TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Publicado
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {canUpdate && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(announcement) => {
                const isDraft = announcement.announcement_status_id === ANNOUNCEMENT_STATUS.DRAFT
                const isPublished =
                  announcement.announcement_status_id === ANNOUNCEMENT_STATUS.PUBLISHED
                return (
                  <TableRow key={announcement.id}>
                    <TableCell className='max-w-[320px]'>
                      <button
                        type='button'
                        className='block w-full text-left'
                        onClick={() => openAnnouncement(announcement)}
                        title='Ver aviso completo'
                      >
                        <span className='block truncate font-medium hover:underline'>
                          {announcement.title}
                        </span>
                        <span className='block truncate text-[12px] text-[var(--ink-3)]'>
                          {announcement.content}
                        </span>
                      </button>
                    </TableCell>
                    <TableCell>{announcement.type?.name ?? '—'}</TableCell>
                    <TableCell className='hidden lg:table-cell'>
                      {announcement.priority?.name ?? '—'}
                    </TableCell>
                    <TableCell className='hidden md:table-cell tabular-nums'>
                      {announcement.reads_count ?? 0}
                    </TableCell>
                    <TableCell className='hidden md:table-cell'>
                      {announcement.publish_at
                        ? displayFormatDate(announcement.publish_at, 'mediumDate')
                        : '—'}
                    </TableCell>
                    <TableCell>
                      <StatusPill tone={announcementTone(announcement)}>
                        {announcement.status?.name ?? '—'}
                      </StatusPill>
                    </TableCell>
                    {canUpdate && (
                      <TableCell className='text-right'>
                        <div className='flex justify-end gap-1.5'>
                          {(isDraft || isPublished) && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8'
                              onClick={() => navigate(`/announcement/${announcement.id}/edit`)}
                              aria-label={`Editar aviso ${announcement.title}`}
                            >
                              <Pencil className='size-4' />
                            </Button>
                          )}
                          {isDraft && (
                            <Button
                              size='sm'
                              className='h-7 rounded-full px-2.5 text-[12px]'
                              onClick={() => publishMutation.mutate(announcement)}
                              disabled={publishMutation.isPending}
                            >
                              <Megaphone className='size-3.5' />
                              Publicar
                            </Button>
                          )}
                          {isPublished && (
                            <Button
                              size='sm'
                              variant='outline'
                              className='h-7 rounded-full px-2.5 text-[12px]'
                              onClick={() => archiveMutation.mutate(announcement)}
                              disabled={archiveMutation.isPending}
                            >
                              <Archive className='size-3.5' />
                              Archivar
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    )}
                  </TableRow>
                )
              }}
              renderCardTitle={(announcement) => announcement.title}
              renderCardFields={(announcement) => [
                { label: 'Tipo', value: announcement.type?.name ?? '—' },
                { label: 'Prioridad', value: announcement.priority?.name ?? '—' },
                { label: 'Lecturas', value: String(announcement.reads_count ?? 0) },
                {
                  label: 'Publicado',
                  value: announcement.publish_at
                    ? displayFormatDate(announcement.publish_at, 'mediumDate')
                    : '—',
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={announcementTone(announcement)}>
                      {announcement.status?.name ?? '—'}
                    </StatusPill>
                  ),
                },
              ]}
              onRowClick={(announcement) => openAnnouncement(announcement)}
              renderActions={
                canUpdate
                  ? (announcement) => {
                      const isDraft =
                        announcement.announcement_status_id === ANNOUNCEMENT_STATUS.DRAFT
                      const isPublished =
                        announcement.announcement_status_id === ANNOUNCEMENT_STATUS.PUBLISHED
                      return (
                        <div className='flex justify-end gap-1.5'>
                          {(isDraft || isPublished) && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8'
                              onClick={() => navigate(`/announcement/${announcement.id}/edit`)}
                              aria-label={`Editar aviso ${announcement.title}`}
                            >
                              <Pencil className='size-4' />
                            </Button>
                          )}
                          {isDraft && (
                            <Button
                              size='sm'
                              className='h-7 rounded-full px-2.5 text-[12px]'
                              onClick={() => publishMutation.mutate(announcement)}
                              disabled={publishMutation.isPending}
                              aria-label={`Publicar aviso ${announcement.title}`}
                            >
                              <Megaphone className='size-3.5' />
                              Publicar
                            </Button>
                          )}
                          {isPublished && (
                            <Button
                              size='sm'
                              variant='outline'
                              className='h-7 rounded-full px-2.5 text-[12px]'
                              onClick={() => archiveMutation.mutate(announcement)}
                              disabled={archiveMutation.isPending}
                              aria-label={`Archivar aviso ${announcement.title}`}
                            >
                              <Archive className='size-3.5' />
                              Archivar
                            </Button>
                          )}
                        </div>
                      )
                    }
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
