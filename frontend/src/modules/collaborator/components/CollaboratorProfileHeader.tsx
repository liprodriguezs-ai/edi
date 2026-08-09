import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type { ICollaborator } from '@/modules/collaborator/schema'
import { ProfileIdentity } from '@/shared/components/atoms/ProfileIdentity'
import { Skeleton } from 'boneyard-js/react'
import { ArrowLeft, Pencil, UserCheck, UserX } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

function getTenure(contractAt?: string): string | null {
  if (!contractAt) return null
  const start = new Date(contractAt)
  const now = new Date()

  let years = now.getFullYear() - start.getFullYear()
  let months = now.getMonth() - start.getMonth()

  if (months < 0) {
    years--
    months += 12
  }

  const parts: string[] = []
  if (years > 0) parts.push(`${years} año${years > 1 ? 's' : ''}`)
  if (months > 0) parts.push(`${months} mes${months > 1 ? 'es' : ''}`)

  return parts.length > 0 ? parts.join(', ') : 'Menos de un mes'
}

interface CollaboratorProfileHeaderProps {
  collaborator: ICollaborator | null
  isLoading: boolean
  onEdit: () => void
  onTerminate?: () => void
  onReactivate?: () => void
}
 
export function CollaboratorProfileHeader({
  collaborator,
  isLoading,
  onEdit,
  onTerminate,
  onReactivate,
}: CollaboratorProfileHeaderProps) {
  const navigate = useNavigate()

  const fullName = collaborator?.full_name || collaborator?.name || '—'
  const jobPosition = collaborator?.employee_position?.name
  const area = collaborator?.employee_shift?.name
  const tenure = getTenure(collaborator?.contract_at)
  const isContracted = collaborator?.contracted

  const subtitleParts = [jobPosition, area, tenure].filter(Boolean)

  return (
    <Skeleton name='collaborator-profile-header' loading={isLoading}>
      <div className='flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between'>
        <div className='flex items-center gap-3'>
          <Button
            variant='ghost'
            size='icon'
            onClick={() => navigate(-1)}
            className='shrink-0'
            aria-label='Volver'
          >
            <ArrowLeft className='h-4 w-4' />
          </Button>

          <ProfileIdentity
            number={collaborator?.number}
            name={fullName}
            subtitle={subtitleParts.length > 0 ? subtitleParts.join(' · ') : undefined}
          />
        </div>

        <div className='flex items-center gap-2 ml-8 sm:ml-0'>
          {isContracted !== undefined && (
            <Badge variant={isContracted ? 'default' : 'secondary'} className='shrink-0'>
              {isContracted ? 'Contratado' : 'No contratado'}
            </Badge>
          )}
          {collaborator?.is_active === false ? (
            onReactivate && (
              <Button size='sm' variant='outline' onClick={onReactivate}>
                <UserCheck className='mr-1.5 h-3.5 w-3.5' />
                Reactivar
              </Button>
            )
          ) : (
            <>
              <Button size='sm' variant='outline' onClick={onEdit}>
                <Pencil className='mr-1.5 h-3.5 w-3.5' />
                Editar
              </Button>
              {onTerminate && (
                <Button
                  size='sm'
                  variant='outline'
                  className='text-destructive hover:text-destructive'
                  onClick={onTerminate}
                >
                  <UserX className='mr-1.5 h-3.5 w-3.5' />
                  Dar de baja
                </Button>
              )}
            </>
          )}
        </div>
      </div>
    </Skeleton>
  )
}
