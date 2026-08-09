import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { COMMUNITY_PROJECT_STATUS, type ICommunityProject } from '@/modules/communityProject/schema'
import { ProjectFormModal } from '@/modules/communityProject/components/ProjectFormModal'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { useCommunityProjectStore } from '@/modules/communityProject/store/communityProjectStore'
import { StatusPill, type PillTone } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { Download, Loader2, Plus } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

export function projectTone(project: ICommunityProject): PillTone {
  switch (project.community_project_status_id) {
    case COMMUNITY_PROJECT_STATUS.ACTIVE:
      return 'g'
    case COMMUNITY_PROJECT_STATUS.FULLY_FUNDED:
      return 'b'
    case COMMUNITY_PROJECT_STATUS.DRAFT:
      return 'y'
    case COMMUNITY_PROJECT_STATUS.EXECUTED:
      return 'g'
    default:
      return 'r'
  }
}

export const CommunityProjectListPage = () => {
  const navigate = useNavigate()
  const [modalOpen, setModalOpen] = useState(false)

  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<ICommunityProject>({
      store: useCommunityProjectStore,
      resource: CommunityProjectResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'created_at', direction: 'desc' }],
        includes: [
          { relation: 'status' },
          { relation: 'category' },
          { relation: 'participationType' },
        ],
      },
    })
  const { entities, pagination, isLoading } = useCommunityProjectStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Proyectos Comunitarios'
        description='Proyectos con meta de financiamiento del condominio'
        actions={
          <>
            <PermissionGuard permission='report.community_project'>
              <Button
                variant='outline'
                onClick={() => onReport('CommunityProject', 'xlsx', 'proyectos')}
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
            <PermissionGuard permission='create.community_project'>
              <Button className='rounded-full' onClick={() => setModalOpen(true)}>
                <Plus className='size-4' />
                Nuevo proyecto
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar proyectos…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Sin proyectos comunitarios'
            description='Crea el primero para financiar mejoras del condominio.'
          />
        ) : (
          <>
            <ResponsiveDataTable<ICommunityProject>
              rows={entities}
              onRowClick={(project) => navigate(`/community-project/${project.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Proyecto</TableHead>
                    <TableHead scope='col'>Categoría</TableHead>
                    <TableHead scope='col'>Participación</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Meta
                    </TableHead>
                    <TableHead scope='col'>Límite</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(project) => (
                <TableRow
                  key={project.id}
                  className='cursor-pointer'
                  onClick={() => navigate(`/community-project/${project.id}`)}
                >
                  <TableCell className='font-medium'>{project.title}</TableCell>
                  <TableCell>{project.category?.name ?? '—'}</TableCell>
                  <TableCell>{project.participation_type?.name ?? '—'}</TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {project.goal_amount != null ? displayMoney(project.goal_amount) : '—'}
                  </TableCell>
                  <TableCell>
                    {project.due_date ? displayFormatDate(project.due_date, 'mediumDate') : '—'}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={projectTone(project)}>
                      {project.status?.name ?? '—'}
                    </StatusPill>
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(project) => project.title}
              renderCardFields={(project) => [
                { label: 'Categoría', value: project.category?.name ?? '—' },
                { label: 'Participación', value: project.participation_type?.name ?? '—' },
                {
                  label: 'Meta',
                  value: (
                    <span className='tabular-nums'>
                      {project.goal_amount != null ? displayMoney(project.goal_amount) : '—'}
                    </span>
                  ),
                },
                {
                  label: 'Límite',
                  value: project.due_date ? displayFormatDate(project.due_date, 'mediumDate') : '—',
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={projectTone(project)}>
                      {project.status?.name ?? '—'}
                    </StatusPill>
                  ),
                },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ProjectFormModal
        isOpen={modalOpen}
        onClose={() => setModalOpen(false)}
        onCreated={() => onSearch({ ...paramsSearch })}
      />
    </div>
  )
}
