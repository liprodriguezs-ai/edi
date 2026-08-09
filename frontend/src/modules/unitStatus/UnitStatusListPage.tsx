import { unitStatusFormSchema, type IUnitStatus, type UnitStatusFormValues } from '@/modules/unitStatus/schema'
import { UnitStatusResource } from '@/modules/unitStatus/services/UnitStatusResource'
import { useUnitStatusStore } from '@/modules/unitStatus/store/unitStatusStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const UnitStatusListPage = () => (
  <CatalogCrudModule<IUnitStatus, UnitStatusFormValues>
    title="Estados de Unidad"
    description="Estados de vivienda de tu condominio (activa, vacante…)"
    newLabel="Nuevo estado"
    editLabel="Editar estado"
    searchPlaceholder="Buscar estados…"
    permissionEntity="unit_status"
    store={useUnitStatusStore}
    resource={UnitStatusResource}
    schema={unitStatusFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      color: entity?.color ?? '#2E6B45',
      order: entity?.order ?? null,
      is_active: entity?.is_active ?? true,
      is_billable: entity?.is_billable ?? true,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Activa' },
      { name: 'color', label: 'Color', type: 'color', colSpan: 4 },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 4 },
      { name: 'is_billable', label: 'Recibe cargos (facturable)', type: 'switch', colSpan: 6 },
    ]}
    columns={[{
      key: 'is_billable',
      label: 'Facturable',
      render: (s) => <span className="text-xs">{s.is_billable ? 'Sí' : 'No'}</span>,
    }, {
      key: 'color',
      label: 'Color',
      render: (s) => (
        <span className="flex items-center gap-2">
          <span className="size-3.5 rounded-full border border-border" style={{ backgroundColor: s.color ?? '#ccc' }} />
          <span className="text-xs text-muted-foreground">{s.color ?? '—'}</span>
        </span>
      ),
    }]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
