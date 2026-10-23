import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { createCrudApi } from '@/shared/api/resourceApi'
import {
  CatalogCrudModule,
  type CatalogColumn,
  type CatalogFieldConfig,
} from '@/shared/components/catalog/CatalogCrudModule'
import { createEntityStore } from '@/shared/store/EntityStore'
import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export interface ICatalogRow extends IEntity {
  name: string
  color?: string | null
  icon?: string | null
  order?: number | null
  is_active?: boolean
  /** Fila de sistema (HasCatalogKey): usada por flujos automáticos, no eliminable */
  is_system?: boolean
  requires_floor?: boolean
  is_billable?: boolean
}

const catalogFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  order: z.number().int().nullable(),
  is_active: z.boolean(),
  color: z.string().optional(),
  icon: z.string().optional(),
  requires_floor: z.boolean().optional(),
  is_billable: z.boolean().optional(),
})

export type CatalogFormValues = z.infer<typeof catalogFormSchema>

interface CatalogFlagConfig {
  /** Columna booleana de comportamiento (ej. requires_floor) */
  name: 'requires_floor' | 'is_billable'
  label: string
  columnLabel: string
  defaultValue: boolean
}

export interface CatalogPageConfig {
  title: string
  description: string
  /** Sustantivo del registro, ej. 'tipo de proveedor' → "Nuevo tipo de proveedor" */
  itemLabel: string
  /** Género del sustantivo, para concordar el botón: 'ocupación' → "Nueva ocupación". */
  itemGender?: 'm' | 'f'
  /** Entidad de permisos y base del endpoint (snake_case), ej. 'provider_type' */
  entity: string
  withColor?: boolean
  /** Icono emoji editable (announcement_type) */
  withIcon?: boolean
  flags?: CatalogFlagConfig[]
}

/**
 * Fábrica de páginas CRUD para los catálogos autoadministrables de la organización.
 * Una página = una config declarativa; schema/resource/store/columnas se
 * generan aquí (DRY: nada de duplicar 10 módulos idénticos).
 */
export function createCatalogPage(config: CatalogPageConfig) {
  const resource = createCrudApi<ICatalogRow, CatalogFormValues>({
    basePath: `/${config.entity}`,
  })
  const store = createEntityStore<ICatalogRow>(`catalog_${config.entity}`)

  const fields: CatalogFieldConfig[] = [
    { name: 'name', label: 'Nombre', required: true },
    ...(config.withColor ? [{ name: 'color', label: 'Color', type: 'color' as const, colSpan: 6 as const }] : []),
    ...(config.withIcon ? [{ name: 'icon', label: 'Icono (emoji)', colSpan: 6 as const, placeholder: '📢' }] : []),
    ...(config.flags ?? []).map((flag) => ({
      name: flag.name,
      label: flag.label,
      type: 'switch' as const,
      colSpan: 6 as const,
    })),
    { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
  ]

  const columns: CatalogColumn<ICatalogRow>[] = [
    ...(config.flags ?? []).map((flag) => ({
      key: flag.name,
      label: flag.columnLabel,
      render: (row: ICatalogRow) => (
        <StatusPill tone={row[flag.name] ? 'g' : 'b'}>{row[flag.name] ? 'Sí' : 'No'}</StatusPill>
      ),
    })),
    {
      key: 'is_active',
      label: 'Estado',
      render: (row) => (
        <StatusPill tone={row.is_active ? 'g' : 'r'}>{row.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
      ),
    },
  ]

  const buildDefaults = (entity?: ICatalogRow | null): CatalogFormValues => ({
    name: entity?.name ?? '',
    order: entity?.order ?? null,
    is_active: entity?.is_active ?? true,
    ...(config.withColor ? { color: entity?.color ?? '#6B7280' } : {}),
    ...(config.withIcon ? { icon: entity?.icon ?? '' } : {}),
    ...Object.fromEntries(
      (config.flags ?? []).map((flag) => [flag.name, entity?.[flag.name] ?? flag.defaultValue]),
    ),
  })

  return function CatalogPage() {
    return (
      <CatalogCrudModule<ICatalogRow, CatalogFormValues>
        title={config.title}
        description={config.description}
        newLabel={`${config.itemGender === 'f' ? 'Nueva' : 'Nuevo'} ${config.itemLabel}`}
        editLabel={`Editar ${config.itemLabel}`}
        searchPlaceholder="Buscar…"
        permissionEntity={config.entity}
        store={store}
        resource={resource}
        schema={catalogFormSchema}
        fields={fields}
        columns={columns}
        buildDefaults={buildDefaults}
        initialSearch={{ sort: [{ field: 'order', direction: 'asc' }] }}
      />
    )
  }
}

export interface ISimpleCatalogRow extends IEntity {
  name: string
  is_active?: boolean
  /** Fila de sistema (HasCatalogKey): usada por flujos automáticos, no eliminable */
  is_system?: boolean
}

const simpleCatalogFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  description: z.string().optional(),
  is_active: z.boolean(),
})

export type SimpleCatalogFormValues = z.infer<typeof simpleCatalogFormSchema>

export interface SimpleCatalogPageConfig {
  title: string
  description: string
  /** Sustantivo del registro, ej. 'parentesco' → "Nuevo parentesco" */
  itemLabel: string
  /** Género del sustantivo, para concordar el botón: 'ocupación' → "Nueva ocupación". */
  itemGender?: 'm' | 'f'
  /** Entidad de permisos y base del endpoint (snake_case), ej. 'caregiver_relationship' */
  entity: string
}

/**
 * Variante de {@link createCatalogPage} para catálogos SIN columna `order`
 * (name/description/is_active únicamente — design.md §3.2 de
 * edi-clinic-and-child-registry: caregiver_relationship, education_level,
 * occupation). Ordena por `name` en vez de `order`.
 */
export function createSimpleCatalogPage(config: SimpleCatalogPageConfig) {
  const resource = createCrudApi<ISimpleCatalogRow, SimpleCatalogFormValues>({
    basePath: `/${config.entity}`,
  })
  const store = createEntityStore<ISimpleCatalogRow>(`catalog_${config.entity}`)

  const fields: CatalogFieldConfig[] = [
    { name: 'name', label: 'Nombre', required: true },
    { name: 'description', label: 'Descripción', type: 'textarea' },
    { name: 'is_active', label: 'Activo', type: 'switch', colSpan: 6 },
  ]

  const columns: CatalogColumn<ISimpleCatalogRow>[] = [
    {
      key: 'is_active',
      label: 'Estado',
      render: (row) => (
        <StatusPill tone={row.is_active ? 'g' : 'r'}>{row.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
      ),
    },
  ]

  const buildDefaults = (entity?: ISimpleCatalogRow | null): SimpleCatalogFormValues => ({
    name: entity?.name ?? '',
    description: entity?.description ?? '',
    is_active: entity?.is_active ?? true,
  })

  return function SimpleCatalogPage() {
    return (
      <CatalogCrudModule<ISimpleCatalogRow, SimpleCatalogFormValues>
        title={config.title}
        description={config.description}
        newLabel={`${config.itemGender === 'f' ? 'Nueva' : 'Nuevo'} ${config.itemLabel}`}
        editLabel={`Editar ${config.itemLabel}`}
        searchPlaceholder="Buscar…"
        permissionEntity={config.entity}
        store={store}
        resource={resource}
        schema={simpleCatalogFormSchema}
        fields={fields}
        columns={columns}
        buildDefaults={buildDefaults}
        initialSearch={{ sort: [{ field: 'name', direction: 'asc' }] }}
      />
    )
  }
}
