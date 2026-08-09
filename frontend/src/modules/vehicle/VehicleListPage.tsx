import { vehicleFormSchema, type IVehicle, type VehicleFormValues } from '@/modules/vehicle/schema'
import { VehicleResource } from '@/modules/vehicle/services/VehicleResource'
import { useVehicleStore } from '@/modules/vehicle/store/vehicleStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { CatalogType } from '@/shared/hooks/useCatalogs'

export const VehicleListPage = () => (
  <CatalogCrudModule<IVehicle, VehicleFormValues>
    title='Vehículos'
    description='Vehículos registrados por unidad'
    newLabel='Registrar vehículo'
    editLabel='Editar vehículo'
    searchPlaceholder='Buscar por placa, marca o modelo…'
    permissionEntity='vehicle'
    reportModel='Vehicle'
    reportTitle='vehiculos'
    store={useVehicleStore}
    resource={VehicleResource}
    schema={vehicleFormSchema}
    nameLabel='Placa'
    renderName={(v) => v.plate}
    buildDefaults={(entity) => ({
      unit_id: entity?.unit_id ?? '',
      plate: entity?.plate ?? '',
      make: entity?.make ?? '',
      model: entity?.model ?? '',
      color: entity?.color ?? '',
      year: entity?.year ?? null,
    })}
    fields={[
      {
        name: 'unit_id',
        label: 'Unidad',
        type: 'catalog',
        catalog: CatalogType.UNIT,
        required: true,
      },
      { name: 'plate', label: 'Placa', required: true, placeholder: 'ABC-123-4', colSpan: 6 },
      { name: 'year', label: 'Año', type: 'number', colSpan: 6 },
      { name: 'make', label: 'Marca', placeholder: 'Honda', colSpan: 6 },
      { name: 'model', label: 'Modelo', placeholder: 'Civic', colSpan: 6 },
      { name: 'color', label: 'Color', placeholder: 'Blanco', colSpan: 6 },
    ]}
    columns={[
      {
        key: 'vehicle',
        label: 'Vehículo',
        render: (v) => [v.make, v.model, v.year].filter(Boolean).join(' ') || '—',
      },
      { key: 'color', label: 'Color' },
      {
        key: 'unit',
        label: 'Unidad',
        render: (v) => v.unit?.full_identifier ?? v.unit?.number ?? '—',
      },
    ]}
    initialSearch={{
      sort: [{ field: 'plate', direction: 'asc' }],
      includes: [{ relation: 'unit' }],
    }}
  />
)
