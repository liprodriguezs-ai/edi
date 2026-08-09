import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  formatGender,
  formatMaritalStatus,
  formatSalary,
} from '@/modules/collaborator/helpers/collaboratorHelper'
import type { ICollaborator } from '@/modules/collaborator/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Skeleton } from 'boneyard-js/react'
import { memo } from 'react'

function DataField({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className='space-y-1'>
      <dt className='text-xs font-medium uppercase tracking-wider text-muted-foreground'>
        {label}
      </dt>
      <dd className='text-sm'>{value ?? '—'}</dd>
    </div>
  )
}

interface MyDataCardProps {
  collaborator: ICollaborator | null
  isLoading: boolean
}
 
function MyDataCardInner({ collaborator, isLoading }: MyDataCardProps) {
  const fullName =
    collaborator?.full_name ??
    [collaborator?.name, collaborator?.last_name, collaborator?.second_last_name]
      .filter(Boolean)
      .join(' ')

  return (
    <Skeleton name='my-data-card' loading={isLoading}>
      <Card>
        <CardHeader className='pb-3'>
          <CardTitle className='text-base font-semibold'>Mis datos</CardTitle>
        </CardHeader>
        <CardContent className='space-y-6'>
          <section>
            <h3 className='mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground'>
              Datos básicos
            </h3>
            <dl className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
              <DataField label='Nombre' value={fullName || undefined} />
              <DataField label='Correo electrónico' value={collaborator?.email} />
              <DataField label='Teléfono' value={collaborator?.phone} />
              <DataField label='CURP' value={collaborator?.curp} />
              <DataField label='RFC' value={collaborator?.rfc} />
              <DataField label='NSS' value={collaborator?.nss} />
              <DataField
                label='Fecha de nacimiento'
                value={displayFormatDate(collaborator?.date_of_birth, 'mediumDate')}
              />
            </dl>
          </section>
          <section>
            <h3 className='mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground'>
              Datos personales
            </h3>
            <dl className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
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
              <DataField
                label='Identificación oficial'
                value={collaborator?.official_identification}
              />
              <DataField label='Domicilio' value={collaborator?.address} />
            </dl>
          </section>
          <section>
            <h3 className='mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground'>
              Información laboral
            </h3>
            <dl className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
              <DataField label='Puesto' value={collaborator?.job_position?.name} />
              <DataField label='Área' value={collaborator?.area?.name} />
              <DataField label='Tipo de contrato' value={collaborator?.contract_type?.name} />
              <DataField
                label='Fecha de ingreso'
                value={displayFormatDate(collaborator?.contract_at, 'mediumDate')}
              />
              <DataField
                label='Fin de contrato'
                value={displayFormatDate(collaborator?.contract_end_at, 'mediumDate')}
              />
              <div className='space-y-1'>
                <dt className='text-xs font-medium uppercase tracking-wider text-muted-foreground'>
                  Estatus
                </dt>
                <dd>
                  <Badge variant={collaborator?.contracted ? 'default' : 'secondary'}>
                    {collaborator?.contracted ? 'Activo' : 'No contratado'}
                  </Badge>
                </dd>
              </div>
            </dl>
          </section>
          <section>
            <h3 className='mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground'>
              Datos de contrato
            </h3>
            <dl className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
              <DataField label='Salario' value={formatSalary(collaborator?.salary?.amount)} />
              <DataField label='Turno' value={collaborator?.work_shift?.name} />
              <DataField label='Horario específico' value={collaborator?.specific_schedule} />
            </dl>
          </section>
          <section>
            <h3 className='mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground'>
              Beneficiarios
            </h3>
            <dl className='grid grid-cols-1 gap-3'>
              <DataField label='Beneficiario' value={collaborator?.beneficiary} />
            </dl>
          </section>
        </CardContent>
      </Card>
    </Skeleton>
  )
}

export const MyDataCard = memo(MyDataCardInner)
