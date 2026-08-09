import { packageCarrierFormSchema, type IPackageCarrier, type PackageCarrierFormValues } from '@/modules/package/carrier/schema'
import { CarrierResource } from '@/modules/package/carrier/services/CarrierResource'
import { usePackageCarrierStore } from '@/modules/package/carrier/store/carrierStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const PackageCarrierListPage = () => (
  <CatalogCrudModule<IPackageCarrier, PackageCarrierFormValues>
    title="Transportistas"
    description="Transportistas registrados para la recepción de paquetes en el condominio."
    newLabel="Nuevo transportista"
    editLabel="Editar transportista"
    searchPlaceholder="Buscar transportistas…"
    permissionEntity="package_carrier"
    store={usePackageCarrierStore}
    resource={CarrierResource}
    schema={packageCarrierFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      is_active: entity?.is_active ?? true,
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'DHL' },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
      { name: 'order', label: 'Orden de aparición', type: 'number', colSpan: 6, placeholder: '0' },
    ]}
    columns={[
      {
        key: 'is_active',
        label: 'Estado',
        render: (c) => (
          <StatusPill tone={c.is_active ? 'g' : 'r'}>{c.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
        ),
      },
      {
        key: 'order',
        label: 'Orden',
        render: (c) => <span className="text-xs text-muted-foreground">{c.order ?? 0}</span>,
      },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
