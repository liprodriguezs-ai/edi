import { towerFormSchema, type ITower, type TowerFormValues } from '@/modules/tower/schema'
import { TowerResource } from '@/modules/tower/services/TowerResource'
import { useTowerStore } from '@/modules/tower/store/towerStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { CatalogType } from '@/shared/hooks/useCatalogs'

export const TowerListPage = () => (
  <CatalogCrudModule<ITower, TowerFormValues>
    title="Torres"
    description="Torres o edificios del condominio"
    newLabel="Nueva torre"
    editLabel="Editar torre"
    searchPlaceholder="Buscar torres…"
    permissionEntity="tower"
    store={useTowerStore}
    resource={TowerResource}
    schema={towerFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      floors_count: entity?.floors_count ?? null,
      section_id: entity?.section_id ?? undefined,
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Torre A' },
      { name: 'floors_count', label: 'Pisos', type: 'number', colSpan: 6 },
      { name: 'section_id', label: 'Sección', type: 'catalog', catalog: CatalogType.SECTION },
    ]}
    columns={[
      { key: 'floors_count', label: 'Pisos' },
      { key: 'section', label: 'Sección', render: (t) => t.section?.name ?? '—' },
    ]}
    initialSearch={{
      sort: [{ field: 'order', direction: 'asc' }],
      includes: [{ relation: 'section' }],
    }}
  />
)
