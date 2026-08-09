import { userProfileFormSchema, type IUserProfile, type UserProfileFormValues } from '@/modules/userProfile/schema'
import { UserProfileResource } from '@/modules/userProfile/services/UserProfileResource'
import { useUserProfileStore } from '@/modules/userProfile/store/userProfileStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { CatalogType } from '@/shared/hooks/useCatalogs'
import { displayFormatDate } from '@/shared/utils/dateHelper'

export const UserProfileListPage = () => (
  <CatalogCrudModule<IUserProfile, UserProfileFormValues>
    title="Perfiles de Residente"
    description="Datos personales por condominio (RFC, CURP, contacto de emergencia)"
    newLabel="Nuevo perfil"
    editLabel="Editar perfil"
    searchPlaceholder="Buscar por RFC o CURP…"
    permissionEntity="user_profile"
    store={useUserProfileStore}
    resource={UserProfileResource}
    schema={userProfileFormSchema}
    nameLabel="Persona"
    renderName={(p) => p.user?.full_name ?? p.user?.name ?? '—'}
    buildDefaults={(entity) => ({
      user_id: entity?.user_id ?? '',
      rfc: entity?.rfc ?? '',
      curp: entity?.curp ?? '',
      birthdate: entity?.birthdate ?? '',
      address: entity?.address ?? '',
      emergency_contact_name: entity?.emergency_contact_name ?? '',
      emergency_contact_phone: entity?.emergency_contact_phone ?? '',
      notes: entity?.notes ?? '',
    })}
    fields={[
      { name: 'user_id', label: 'Persona', type: 'catalog', catalog: CatalogType.USER, required: true },
      { name: 'rfc', label: 'RFC', placeholder: 'XAXX010101000', colSpan: 6 },
      { name: 'curp', label: 'CURP', colSpan: 6 },
      { name: 'birthdate', label: 'Fecha de nacimiento', type: 'text', placeholder: 'AAAA-MM-DD', colSpan: 6 },
      { name: 'emergency_contact_name', label: 'Contacto de emergencia', colSpan: 6 },
      { name: 'emergency_contact_phone', label: 'Teléfono de emergencia', colSpan: 6 },
      { name: 'address', label: 'Dirección', type: 'textarea' },
      { name: 'notes', label: 'Notas', type: 'textarea' },
    ]}
    columns={[
      { key: 'rfc', label: 'RFC' },
      { key: 'curp', label: 'CURP' },
      { key: 'birthdate', label: 'Nacimiento', render: (p) => (p.birthdate ? displayFormatDate(p.birthdate, 'mediumDate') : '—') },
    ]}
    initialSearch={{
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [{ relation: 'user' }],
    }}
  />
)
