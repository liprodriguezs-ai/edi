import { unitTypeFormSchema, type IUnitType, type UnitTypeFormValues } from '@/modules/unitType/schema'
import { UnitTypeResource } from '@/modules/unitType/services/UnitTypeResource'
import { useUnitTypeStore } from '@/modules/unitType/store/unitTypeStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

export const UnitTypeListPage = () => (
  <CatalogCrudModule<IUnitType, UnitTypeFormValues>
    title="Tipos de Unidad"
    description="Tipos de vivienda de tu condominio (casa, departamento…)"
    newLabel="Nuevo tipo"
    editLabel="Editar tipo"
    searchPlaceholder="Buscar tipos…"
    permissionEntity="unit_type"
    store={useUnitTypeStore}
    resource={UnitTypeResource}
    schema={unitTypeFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      order: entity?.order ?? null,
      is_active: entity?.is_active ?? true,
      requires_floor: entity?.requires_floor ?? false,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Casa' },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
      { name: 'requires_floor', label: 'Exige torre y piso (vertical)', type: 'switch', colSpan: 6 },
    ]}
    columns={[
      {
        key: 'requires_floor',
        label: 'Vertical',
        render: (t) => <span className="text-xs">{t.requires_floor ? 'Sí' : 'No'}</span>,
      },
      {
        key: 'is_active',
        label: 'Estado',
        render: (t) => (
          <StatusPill tone={t.is_active ? 'g' : 'r'}>{t.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
        ),
      },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
