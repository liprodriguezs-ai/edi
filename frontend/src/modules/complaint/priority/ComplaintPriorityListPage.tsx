import {
  complaintPriorityFormSchema,
  type IComplaintPriority,
  type ComplaintPriorityFormValues,
} from '@/modules/complaint/priority/schema'
import { PriorityResource } from '@/modules/complaint/priority/services/PriorityResource'
import { useComplaintPriorityStore } from '@/modules/complaint/priority/store/priorityStore'
import { ComplaintPriorityChip } from '@/modules/complaint/components/ComplaintPriorityChip'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const ComplaintPriorityListPage = () => (
  <CatalogCrudModule<IComplaintPriority, ComplaintPriorityFormValues>
    title='Prioridades de Incidencia'
    description='Niveles de prioridad disponibles para triage de las incidencias registradas.'
    newLabel='Nueva prioridad'
    editLabel='Editar prioridad'
    searchPlaceholder='Buscar prioridades…'
    permissionEntity='complaint_priority'
    store={useComplaintPriorityStore}
    resource={PriorityResource}
    schema={complaintPriorityFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      color: entity?.color ?? '#6B7280',
      is_active: entity?.is_active ?? true,
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Alta', colSpan: 6 },
      { name: 'color', label: 'Color', type: 'color', colSpan: 6 },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
      { name: 'order', label: 'Orden de aparición', type: 'number', colSpan: 6, placeholder: '0' },
    ]}
    columns={[
      {
        key: 'color',
        label: 'Vista previa',
        render: (p) => <ComplaintPriorityChip name={p.name} color={p.color} />,
      },
      {
        key: 'is_active',
        label: 'Estado',
        render: (p) => (
          <StatusPill tone={p.is_active ? 'g' : 'r'}>
            {p.is_active ? 'Activo' : 'Inactivo'}
          </StatusPill>
        ),
      },
      {
        key: 'order',
        label: 'Orden',
        render: (p) => <span className='text-xs text-muted-foreground'>{p.order ?? 0}</span>,
      },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
