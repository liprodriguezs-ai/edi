import { unitRelationshipFormSchema, type IUnitRelationship, type UnitRelationshipFormValues } from '@/modules/unitRelationship/schema'
import { UnitRelationshipResource } from '@/modules/unitRelationship/services/UnitRelationshipResource'
import { useUnitRelationshipStore } from '@/modules/unitRelationship/store/unitRelationshipStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const UnitRelationshipListPage = () => (
  <CatalogCrudModule<IUnitRelationship, UnitRelationshipFormValues>
    title="Relaciones de Unidad"
    description="Catálogo central de relaciones persona–unidad (propietario, inquilino…)"
    newLabel="Nueva relación"
    editLabel="Editar relación"
    searchPlaceholder="Buscar relaciones…"
    permissionEntity="unit_relationship"
    store={useUnitRelationshipStore}
    resource={UnitRelationshipResource}
    schema={unitRelationshipFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      order: entity?.order ?? null,
      is_active: entity?.is_active ?? true,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Propietario' },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
