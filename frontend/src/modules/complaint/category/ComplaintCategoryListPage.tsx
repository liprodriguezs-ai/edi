import {
  complaintCategoryFormSchema,
  type IComplaintCategory,
  type ComplaintCategoryFormValues,
} from '@/modules/complaint/category/schema'
import { CategoryResource } from '@/modules/complaint/category/services/CategoryResource'
import { useComplaintCategoryStore } from '@/modules/complaint/category/store/categoryStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const ComplaintCategoryListPage = () => (
  <CatalogCrudModule<IComplaintCategory, ComplaintCategoryFormValues>
    title='Categorías de Incidencia'
    description='Categorías disponibles para clasificar las incidencias registradas en el condominio.'
    newLabel='Nueva categoría'
    editLabel='Editar categoría'
    searchPlaceholder='Buscar categorías…'
    permissionEntity='complaint_category'
    store={useComplaintCategoryStore}
    resource={CategoryResource}
    schema={complaintCategoryFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      is_active: entity?.is_active ?? true,
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Ruido' },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
      { name: 'order', label: 'Orden de aparición', type: 'number', colSpan: 6, placeholder: '0' },
    ]}
    columns={[
      {
        key: 'is_active',
        label: 'Estado',
        render: (c) => (
          <StatusPill tone={c.is_active ? 'g' : 'r'}>
            {c.is_active ? 'Activo' : 'Inactivo'}
          </StatusPill>
        ),
      },
      {
        key: 'order',
        label: 'Orden',
        render: (c) => <span className='text-xs text-muted-foreground'>{c.order ?? 0}</span>,
      },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
