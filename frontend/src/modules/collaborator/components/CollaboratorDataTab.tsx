import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  formatGender,
  formatMaritalStatus,
} from '@/modules/collaborator/helpers/collaboratorHelper'
import { formatWorkDays, type ICollaborator } from '@/modules/collaborator/schema'
import { displayMoney } from '@/shared/utils/money'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Skeleton } from 'boneyard-js/react'

interface CollaboratorDataTabProps {
  collaborator: ICollaborator | null
  isLoading: boolean
}

function DataField({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className='space-y-1'>
      <dt className='text-xs font-medium text-muted-foreground uppercase tracking-wider'>
        {label}
      </dt>
      <dd className='text-sm'>{value || '—'}</dd>
    </div>
  )
}

export function CollaboratorDataTab({ collaborator, isLoading }: CollaboratorDataTabProps) {
  return (
    <Skeleton name='collaborator-data-tab' loading={isLoading}>
      <div className='grid grid-cols-1 gap-6 lg:grid-cols-2'>
        <Card>
          <CardHeader className='pb-4'>
            <CardTitle className='text-base'>Datos básicos</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className='grid grid-cols-1 gap-4 sm:grid-cols-2'>
              <DataField
                label='Nombre'
                value={
                  collaborator?.full_name ??
                  [collaborator?.name, collaborator?.last_name, collaborator?.second_last_name]
                    .filter(Boolean)
                    .join(' ')
                }
              />
              <DataField label='Correo electrónico' value={collaborator?.email} />
              <DataField label='Teléfono' value={collaborator?.phone} />
              <DataField label='RFC' value={collaborator?.rfc} />
              <DataField label='CURP' value={collaborator?.curp} />
              <DataField
                label='Fecha de nacimiento'
                value={displayFormatDate(collaborator?.date_of_birth, 'mediumDate')}
              />
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className='pb-4'>
            <CardTitle className='text-base'>Información laboral</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className='grid grid-cols-1 gap-4 sm:grid-cols-2'>
              <DataField label='Puesto' value={collaborator?.employee_position?.name} />
              <DataField label='Frecuencia de pago' value={collaborator?.pay_frequency?.name} />
              <DataField label='Días laborales' value={formatWorkDays(collaborator?.work_days)} />
              <DataField
                label='Fecha de contrato'
                value={displayFormatDate(collaborator?.contract_at, 'mediumDate')}
              />
              <DataField
                label='Fin de contrato'
                value={displayFormatDate(collaborator?.contract_end_at, 'mediumDate')}
              />
              <div className='space-y-1'>
                <dt className='text-xs font-medium text-muted-foreground uppercase tracking-wider'>
                  Estado
                </dt>
                <dd>
                  <Badge variant={collaborator?.contracted ? 'default' : 'secondary'}>
                    {collaborator?.contracted ? 'Contratado' : 'No contratado'}
                  </Badge>
                </dd>
              </div>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className='pb-4'>
            <CardTitle className='text-base'>Datos personales</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className='grid grid-cols-1 gap-4 sm:grid-cols-2'>
              <DataField label='Nacionalidad' value={collaborator?.nationality?.name} />
              <DataField label='Lugar de nacimiento' value={collaborator?.birth_place} />
              <DataField label='Sexo' value={formatGender(collaborator?.gender)} />
              <DataField
                label='Estado civil'
                value={formatMaritalStatus(
                  collaborator?.marital_status?.name,
                  collaborator?.gender
                )}
              />
              <DataField label='INE' value={collaborator?.official_identification} />
              <DataField label='Domicilio' value={collaborator?.address} />
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className='pb-4'>
            <CardTitle className='text-base'>Datos de contrato</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className='grid grid-cols-1 gap-4 sm:grid-cols-2'>
              <DataField
                label='Salario'
                value={
                  collaborator?.base_salary != null
                    ? displayMoney(collaborator.base_salary as never)
                    : undefined
                }
              />
              <DataField label='Turno' value={collaborator?.employee_shift?.name} />
              <DataField label='Horario específico' value={collaborator?.specific_schedule} />
            </dl>
          </CardContent>
        </Card>

        <Card className='lg:col-span-2'>
          <CardHeader className='pb-4'>
            <CardTitle className='text-base'>Beneficiarios</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className='grid grid-cols-1 gap-4'>
              <DataField label='Beneficiario' value={collaborator?.beneficiary} />
            </dl>
          </CardContent>
        </Card>
      </div>
    </Skeleton>
  )
}
