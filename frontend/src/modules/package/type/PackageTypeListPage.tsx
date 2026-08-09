import { packageTypeFormSchema, type IPackageType, type PackageTypeFormValues } from '@/modules/package/type/schema'
import { TypeResource } from '@/modules/package/type/services/TypeResource'
import { usePackageTypeStore } from '@/modules/package/type/store/typeStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const PackageTypeListPage = () => (
  <CatalogCrudModule<IPackageType, PackageTypeFormValues>
    title="Tipos de Paquete"
    description="Tipos de paquete disponibles para la clasificación de envíos en el condominio."
    newLabel="Nuevo tipo"
    editLabel="Editar tipo"
    searchPlaceholder="Buscar tipos de paquete…"
    permissionEntity="package_type"
    store={usePackageTypeStore}
    resource={TypeResource}
    schema={packageTypeFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      is_active: entity?.is_active ?? true,
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Caja' },
      { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
      { name: 'order', label: 'Orden de aparición', type: 'number', colSpan: 6, placeholder: '0' },
    ]}
    columns={[
      {
        key: 'is_active',
        label: 'Estado',
        render: (t) => (
          <StatusPill tone={t.is_active ? 'g' : 'r'}>{t.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
        ),
      },
      {
        key: 'order',
        label: 'Orden',
        render: (t) => <span className="text-xs text-muted-foreground">{t.order ?? 0}</span>,
      },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
