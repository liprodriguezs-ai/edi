import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { ExpedienteStatus, ICollaborator } from '@/modules/collaborator/schema'
import { Skeleton } from 'boneyard-js/react'
import { CheckCircle2 } from 'lucide-react'
import { memo } from 'react'

const STATUS_LABELS: Record<ExpedienteStatus, string> = {
  complete: 'Completo',
  incomplete: 'Incompleto',
  critical: 'Crítico',
}

interface DossierStatusCardProps {
  collaborator: ICollaborator | null
  isLoading: boolean
}

function DossierStatusCardInner({ collaborator, isLoading }: DossierStatusCardProps) {
  const status = collaborator?.expediente_status ?? 'incomplete'
  const uploaded = (collaborator as { docs_uploaded?: number })?.docs_uploaded ?? 0
  const total = collaborator?.total_documents ?? 0
  const percent = total > 0 ? Math.min(100, Math.round((uploaded / total) * 100)) : 0

  return (
    <Skeleton name='dossier-status-card' loading={isLoading}>
      <Card>
        <CardHeader className='pb-2'>
          <CardTitle className='text-base font-semibold'>Mi expediente</CardTitle>
        </CardHeader>
        <CardContent className='space-y-3'>
          <div className='flex flex-wrap items-center gap-2'>
            <Badge
              variant={
                status === 'complete'
                  ? 'default'
                  : status === 'critical'
                    ? 'destructive'
                    : 'secondary'
              }
              className='gap-1'
            >
              {status === 'complete' && <CheckCircle2 className='size-3.5' />}
              {STATUS_LABELS[status]}
            </Badge>
          </div>
          <div className='space-y-1.5'>
            <div className='flex justify-between text-sm text-muted-foreground'>
              <span>{percent}%</span>
            </div>
            <div
              className='h-2 w-full overflow-hidden rounded-full bg-muted'
              role='progressbar'
              aria-valuenow={percent}
              aria-valuemin={0}
              aria-valuemax={100}
            >
              <div
                className='h-full rounded-full bg-primary transition-[width]'
                style={{ width: `${percent}%` }}
              />
            </div>
          </div>
          <p className='text-sm text-muted-foreground'>
            {uploaded} de {total} documentos cargados
          </p>
        </CardContent>
      </Card>
    </Skeleton>
  )
}

export const DossierStatusCard = memo(DossierStatusCardInner)
