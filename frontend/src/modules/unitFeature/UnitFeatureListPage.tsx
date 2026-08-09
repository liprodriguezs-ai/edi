import { unitFeatureFormSchema, type IUnitFeature, type UnitFeatureFormValues } from '@/modules/unitFeature/schema'
import { UnitFeatureResource } from '@/modules/unitFeature/services/UnitFeatureResource'
import { useUnitFeatureStore } from '@/modules/unitFeature/store/unitFeatureStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

export const UnitFeatureListPage = () => (
  <CatalogCrudModule<IUnitFeature, UnitFeatureFormValues>
    title="Características de Unidad"
    description="Amenidades y atributos asignables a las unidades"
    newLabel="Nueva característica"
    editLabel="Editar característica"
    searchPlaceholder="Buscar características…"
    permissionEntity="unit_feature"
    store={useUnitFeatureStore}
    resource={UnitFeatureResource}
    schema={unitFeatureFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      is_active: entity?.is_active ?? true,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Alberca' },
      { name: 'is_active', label: 'Activa', type: 'switch', colSpan: 6 },
    ]}
    columns={[{
      key: 'is_active',
      label: 'Estado',
      render: (f) => (
        <StatusPill tone={f.is_active ? 'g' : 'r'}>{f.is_active ? 'Activa' : 'Inactiva'}</StatusPill>
      ),
    }]}
  />
)
