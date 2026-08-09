import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AuditDiffModal } from '@/modules/audit/AuditDiffModal'
import { AuditTableRow } from '@/modules/audit/components/AuditTableRow'
import { useAuditList } from '@/modules/audit/hooks/useAuditList'
import type { IAudit } from '@/modules/audit/schema'
import { useAuditStore } from '@/modules/audit/store/auditStore'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Download, FileDiff, Loader2 } from 'lucide-react'
import { useCallback, useState } from 'react'

const EVENT_LABELS: Record<IAudit['event'], string> = {
  created: 'Creado',
  updated: 'Actualizado',
  deleted: 'Eliminado',
  restored: 'Restaurado',
}

const EVENT_VARIANTS: Record<IAudit['event'], 'default' | 'secondary' | 'destructive' | 'outline'> =
  {
    created: 'default',
    updated: 'secondary',
    deleted: 'destructive',
    restored: 'outline',
  }

function auditableLabel(audit: IAudit): string {
  const type = audit.auditable_type ?? '—'
  const shortType = type.split('\\').pop() ?? type
  return `${shortType} (${audit.auditable_id.slice(0, 8)}…)`
}

export const AuditList = () => {
  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } = useAuditList()
  const { entities, pagination, isLoading } = useAuditStore()

  const [auditForDiff, setAuditForDiff] = useState<IAudit | null>(null)
  const [diffModalOpen, setDiffModalOpen] = useState(false)

  const handleViewDiff = useCallback((audit: IAudit) => {
    setAuditForDiff(audit)
    setDiffModalOpen(true)
  }, [])

  const handleCloseDiff = useCallback(() => {
    setDiffModalOpen(false)
    setAuditForDiff(null)
  }, [])

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Auditoría'
        description='Registro de cambios del sistema. Revisa quién hizo qué y cuándo.'
        actions={
          <PermissionGuard permission='report.audit'>
            <Button
              variant='outline'
              onClick={() => onReport('Audit', 'xlsx', 'auditoria')}
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
        }
      />

      <section aria-label='Listado de auditoría'>
        <div className='space-y-6'>
          <SearchList
            paramsSearch={paramsSearch}
            onSearch={onSearch}
            onReset={onReset}
            placeholder='Buscar por entidad, evento...'
          />

          {isLoading ? (
            <TableLoadingState />
          ) : entities.length === 0 ? (
            <TableEmptyState />
          ) : (
            <>
              <ResponsiveDataTable<IAudit>
                rows={entities}
                breakpoint='md'
                header={
                  <TableHeader>
                    <TableRow>
                      <TableHead scope='col'>Usuario</TableHead>
                      <TableHead scope='col'>Fecha</TableHead>
                      <TableHead scope='col'>Evento</TableHead>
                      <TableHead scope='col'>Entidad</TableHead>
                      <TableHead scope='col' className='text-right'>
                        Acciones
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                }
                renderRow={(audit) => (
                  <AuditTableRow
                    key={audit.id}
                    entity={audit}
                    canEdit={false}
                    canDelete={false}
                    isLoading={isLoading}
                    isDeleting={false}
                    isSaving={false}
                    onEdit={() => {}}
                    onDelete={() => {}}
                    onDetail={handleViewDiff}
                  />
                )}
                renderCardTitle={(audit) => audit.user?.full_name ?? audit.user?.name ?? '—'}
                renderCardFields={(audit) => [
                  { label: 'Fecha', value: displayFormatDate(audit.created_at) },
                  {
                    label: 'Evento',
                    value: (
                      <Badge variant={EVENT_VARIANTS[audit.event]} className='text-xs'>
                        {EVENT_LABELS[audit.event]}
                      </Badge>
                    ),
                  },
                  { label: 'Entidad', value: auditableLabel(audit) },
                ]}
                renderActions={(audit) => (
                  <Button
                    variant='ghost'
                    size='sm'
                    onClick={() => handleViewDiff(audit)}
                    className='gap-2'
                    aria-label={`Ver cambios del registro de auditoría ${auditableLabel(audit)}`}
                  >
                    <FileDiff className='h-4 w-4' aria-hidden />
                    Ver cambios
                  </Button>
                )}
              />
              {entities.length > 0 && (
                <Pagination pagination={pagination} onPageChanged={onPageChanged} />
              )}
            </>
          )}
        </div>
      </section>

      <AuditDiffModal isOpen={diffModalOpen} audit={auditForDiff} onClose={handleCloseDiff} />
    </div>
  )
}
