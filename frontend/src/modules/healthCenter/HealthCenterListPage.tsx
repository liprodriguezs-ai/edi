import {
  healthCenterFormSchema,
  type HealthCenterFormValues,
  type IHealthCenter,
} from '@/modules/healthCenter/schema'
import { HealthCenterResource } from '@/modules/healthCenter/services/HealthCenterResource'
import { useHealthCenterStore } from '@/modules/healthCenter/store/healthCenterStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { CatalogType } from '@/shared/hooks/useCatalogs'

export const HealthCenterListPage = () => (
  <CatalogCrudModule<IHealthCenter, HealthCenterFormValues>
    title="Centros de Salud"
    description="Unidades médicas de tu organización donde se atienden los niños"
    newLabel="Nuevo centro"
    editLabel="Editar centro"
    searchPlaceholder="Buscar por nombre o CLUES…"
    permissionEntity="health_center"
    store={useHealthCenterStore}
    resource={HealthCenterResource}
    schema={healthCenterFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      clues_code: entity?.clues_code ?? '',
      municipality_id: entity?.municipality_id ?? '',
      address: entity?.address ?? '',
      is_active: entity?.is_active ?? true,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true },
      { name: 'clues_code', label: 'CLUES', placeholder: 'DFSSA003962', colSpan: 6 },
      {
        name: 'municipality_id',
        label: 'Municipio',
        type: 'catalog',
        catalog: CatalogType.MUNICIPALITY,
        required: true,
        colSpan: 6,
      },
      { name: 'address', label: 'Dirección', type: 'textarea', required: true },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
    ]}
    columns={[
      { key: 'clues_code', label: 'CLUES', render: (c) => c.clues_code || '—' },
      { key: 'municipality', label: 'Municipio', render: (c) => c.municipality?.name ?? '—' },
      {
        key: 'is_active',
        label: 'Estado',
        render: (c) => (
          <StatusPill tone={c.is_active ? 'g' : 'r'}>{c.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
        ),
      },
    ]}
    initialSearch={{
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [{ relation: 'municipality' }],
    }}
  />
)
