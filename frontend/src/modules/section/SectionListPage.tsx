import { sectionFormSchema, type ISection, type SectionFormValues } from '@/modules/section/schema'
import { SectionResource } from '@/modules/section/services/SectionResource'
import { useSectionStore } from '@/modules/section/store/sectionStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'

export const SectionListPage = () => (
  <CatalogCrudModule<ISection, SectionFormValues>
    title="Secciones"
    description="Secciones o privadas del condominio"
    newLabel="Nueva sección"
    editLabel="Editar sección"
    searchPlaceholder="Buscar secciones…"
    permissionEntity="section"
    store={useSectionStore}
    resource={SectionResource}
    schema={sectionFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Sección Norte' },
    ]}
    initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
  />
)
