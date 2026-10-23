import {
  practitionerFormSchema,
  type IPractitionerProfile,
  type PractitionerFormValues,
} from '@/modules/practitioner/schema'
import { PractitionerResource } from '@/modules/practitioner/services/PractitionerResource'
import { usePractitionerStore } from '@/modules/practitioner/store/practitionerStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { CatalogType } from '@/shared/hooks/useCatalogs'

export const PractitionerListPage = () => (
  <CatalogCrudModule<IPractitionerProfile, PractitionerFormValues>
    title="Profesionales de Salud"
    description="Personal que aplica Pruebas EDI y consultas en tu organización"
    newLabel="Nuevo profesional"
    editLabel="Editar profesional"
    searchPlaceholder="Buscar por cédula o especialidad…"
    permissionEntity="practitioner_profile"
    store={usePractitionerStore}
    resource={PractitionerResource}
    schema={practitionerFormSchema}
    nameLabel="Profesional"
    renderName={(p) => p.user?.name ?? '—'}
    buildDefaults={(entity) => ({
      user_id: entity?.user_id ?? '',
      license_number: entity?.license_number ?? '',
      specialty: entity?.specialty ?? '',
      health_center_id: entity?.health_center_id ?? '',
    })}
    fields={[
      { name: 'user_id', label: 'Usuario', type: 'catalog', catalog: CatalogType.USER, required: true },
      { name: 'license_number', label: 'Cédula profesional', required: true, colSpan: 6 },
      { name: 'specialty', label: 'Especialidad', required: true, colSpan: 6 },
      {
        name: 'health_center_id',
        label: 'Centro de salud',
        type: 'catalog',
        catalog: CatalogType.HEALTH_CENTER,
        required: true,
      },
    ]}
    columns={[
      { key: 'license_number', label: 'Cédula', className: 'font-mono' },
      { key: 'specialty', label: 'Especialidad' },
      { key: 'health_center', label: 'Centro de salud', render: (p) => p.health_center?.name ?? '—' },
    ]}
    initialSearch={{
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [{ relation: 'user' }, { relation: 'health_center' }],
    }}
  />
)
