import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { TargetPreview } from '@/shared/components/target/TargetPreview'
import { ManualUnitPicker } from '@/modules/chargeBatch/components/ManualUnitPicker'
import { UnitCombobox } from '@/modules/unit/components/UnitCombobox'
import { cn } from '@/lib/utils'
import {
  Building,
  Building2,
  Car,
  Home,
  Landmark,
  LandPlot,
  Layers,
  LayoutGrid,
  ListChecks,
  PieChart,
  Sparkles,
  type LucideIcon,
} from 'lucide-react'
import {
  Controller,
  type Control,
  type FieldErrors,
  type FieldValues,
  type Path,
  type UseFormRegister,
} from 'react-hook-form'

/**
 * Selector de alcance ("¿a quiénes aplica?") para los formularios de Cargos —lote y
 * política—. Tipo/torre/característica son MULTISELECT (varios valores). Renderiza un
 * radiogroup de tipos en TARJETAS + el campo condicional de cada tipo (multiselect de
 * tipo/torre/característica vía `SearchSelect multiple`, `ManualUnitPicker` para selección
 * manual). `by_floor` ("por piso") y `single_unit` ("una sola unidad") quedaron FUERA del
 * set visible — la selección manual cubre el caso de una unidad — pero sus ramas siguen
 * vivas para mostrar datos legacy que aún carguen esos alcances.
 *
 * El módulo de Chat TAMBIÉN usa este componente (alcance de canal, bloques read + write):
 * inyecta la opción `board` (mesa directiva) vía `availableTypes`, reescribe los nombres de
 * los campos RHF vía la prop `names` (prefijo `write_target_*` para el bloque de escritura) y
 * personaliza el encabezado vía `sectionLabel`. Cobranza y Chat comparten además el builder
 * `shared/utils/targetSelector` (la lógica que producía el bug).
 *
 * Genérico sobre el tipo del formulario: `ChargeBatchFormValues`, `ChargePolicyFormValues` y
 * `ChannelFormValues` exponen los mismos campos de alcance, de ahí los `Path<T>`.
 */

/** Catálogo mínimo que el selector necesita para poblar los dropdowns. */
interface TargetCatalogItem {
  id: string
  name?: string
}

export interface SingleTargetCatalogs {
  unitTypes: TargetCatalogItem[]
  towers: TargetCatalogItem[]
  sections?: TargetCatalogItem[]
  features: TargetCatalogItem[]
}

/**
 * Tipos visibles por defecto (orden de las tarjetas). `by_floor`, `single_unit` y
 * `by_share` quedan ocultos del UI. Módulo-privado: la parametrización externa se hace
 * vía la prop `availableTypes`.
 */
const SINGLE_TARGET_VISIBLE_TYPES = [
  'all',
  'by_type',
  'by_tower',
  'by_section',
  'by_feature',
  'with_vehicles',
  'manual',
] as const

/**
 * Etiquetas en español de cada tipo de alcance. Override vía prop `typeLabels`.
 * `board` (mesa directiva) solo lo usa Chat: se incluye acá para que el componente sepa
 * etiquetarlo, pero NO entra a `SINGLE_TARGET_VISIBLE_TYPES` (cobranza no lo muestra).
 */
const SINGLE_TARGET_TYPE_LABELS: Record<string, string> = {
  all: 'Todo el condominio',
  by_type: 'Por tipo de unidad',
  by_tower: 'Por torre',
  by_section: 'Por sección',
  by_floor: 'Por piso',
  manual: 'Selección manual',
  by_feature: 'Por característica',
  with_vehicles: 'Con vehículos',
  by_share: 'Por indiviso',
  single_unit: 'Una sola unidad',
  board: 'Solo la mesa directiva',
}

/** Descripción corta de cada tipo (ayuda al usuario a elegir). */
const SINGLE_TARGET_TYPE_HINTS: Record<string, string> = {
  all: 'Todas las unidades facturables',
  by_type: 'Uno o varios tipos de unidad',
  by_tower: 'Una o varias torres',
  by_section: 'Una o varias secciones',
  by_floor: 'Un piso específico',
  manual: 'Elige unidades una por una',
  by_feature: 'Con alguna de las características elegidas',
  with_vehicles: 'Unidades con al menos un vehículo registrado',
  by_share: 'Prorrateo por indiviso',
  single_unit: 'Una unidad específica',
  board: 'Solo los miembros de la mesa directiva vigente',
}

/** Ícono por tipo de alcance. */
const SINGLE_TARGET_TYPE_ICONS: Record<string, LucideIcon> = {
  all: Building2,
  by_type: LayoutGrid,
  by_tower: Building,
  by_section: LandPlot,
  by_floor: Layers,
  manual: ListChecks,
  by_feature: Sparkles,
  with_vehicles: Car,
  by_share: PieChart,
  single_unit: Home,
  board: Landmark,
}

/**
 * Nombres de los campos RHF que el selector bindea. Default = los de cobranza (lote +
 * política), así cobranza no cambia. Chat los reescribe con el prefijo `write_target_*`
 * para el bloque de escritura (y `target_*` para el de lectura). `unitId`/`unitIds` se usan
 * SOLO para resolver el mensaje de error (su VALOR se cablea por callbacks/props del padre).
 */
export interface SingleTargetNames {
  targetType: string
  unitTypeIds: string
  towerIds: string
  sectionIds: string
  featureIds: string
  floor: string
  unitId: string
  unitIds: string
}

const DEFAULT_SINGLE_TARGET_NAMES: SingleTargetNames = {
  targetType: 'target_type',
  unitTypeIds: 'target_unit_type_ids',
  towerIds: 'target_tower_ids',
  sectionIds: 'target_section_ids',
  featureIds: 'target_feature_ids',
  floor: 'target_floor',
  unitId: 'target_unit_id',
  unitIds: 'target_unit_ids',
}

export interface SingleTargetSelectorProps<T extends FieldValues> {
  control: Control<T>
  register: UseFormRegister<T>
  errors: FieldErrors<T>
  /** Valor actual de `target_type` (el form lo observa con `watch`). */
  targetType: string
  targetUnitId: string | undefined
  targetUnitIds: string[]
  catalogs: SingleTargetCatalogs
  disabled?: boolean
  /** Prefijo de los id HTML (unicidad de accesibilidad): p. ej. `'batch'` | `'policy'`. */
  idPrefix: string
  singleUnitLabel: string
  onSingleUnitLabelChange: (_label: string) => void
  onUnitSelect: (_id: string) => void
  onUnitIdsChange: (_ids: string[]) => void
  /** Tipos a mostrar como tarjetas, en orden. Default: `SINGLE_TARGET_VISIBLE_TYPES`.
   * Es la perilla "SOLO estas opciones". */
  availableTypes?: readonly string[]
  /**
   * Tipos a excluir del set efectivo, restando de `availableTypes` ya resuelto. Es la
   * perilla "SIN estas opciones" (complementaria a `availableTypes`). Default: ninguno.
   */
  excludedTypes?: readonly string[]
  /** Etiquetas por tipo. Default: `SINGLE_TARGET_TYPE_LABELS`. */
  typeLabels?: Record<string, string>
  /**
   * Nombres de los campos RHF a bindear. Default: los de cobranza (`target_*`). Chat pasa el
   * prefijo `write_target_*` para el bloque de escritura.
   */
  names?: SingleTargetNames
  /** Encabezado del bloque. Default: `'Alcance — ¿a quiénes aplica?'` (cobranza). */
  sectionLabel?: string
  /**
   * Forma del campo `floor` (rama legacy `by_floor`): `false` (default) = UN piso como STRING
   * (cobranza, schema `z.string()`); `true` = VARIOS pisos como `string[]` (chat, schema
   * `z.array(z.string())`). Evita que Zod truene al guardar un canal legacy con `by_floor`.
   */
  floorIsMulti?: boolean
  /**
   * Selector de alcance ya construido (payload `target_selector`) para la vista previa en
   * vivo. Se pasa junto con `previewFetcher`. `null`/omitido cuando no aplica (ej. edicion).
   */
  previewSelector?: Record<string, unknown> | null
  /**
   * Fetcher del conteo de destinatarios de la vista previa. Cuando esta presente, el selector
   * renderiza el bloque de vista previa; cuando falta, no se renderiza nada (ni corre query).
   * Endpoint-agnostico: cada consumidor pasa su propio fetcher.
   */
  previewFetcher?: (_payload: {
    target_type: string
    target_selector?: Record<string, unknown> | null
  }) => Promise<number>
  /** Sustantivo del conteo en la vista previa. Default: `'unidades incluidas'`. */
  previewNoun?: string
  /**
   * Discriminador de caché por endpoint para la vista previa (ej. `'charge-batch'` |
   * `'announcement'`). Evita que dos consumidores con distinto significado compartan la
   * misma entrada de caché de React Query. Default: `idPrefix`.
   */
  previewScope?: string
}

/** Lee el mensaje de error de un campo sin perder el tipado genérico del form. */
function errorMessage<T extends FieldValues>(
  errors: FieldErrors<T>,
  name: string
): string | undefined {
  return (errors as Record<string, { message?: string } | undefined>)[name]?.message
}

/** Multiselect de catálogo (tipo / torre / característica) controlado por RHF. */
function TargetCatalogMultiSelect<T extends FieldValues>({
  name,
  htmlId,
  label,
  items,
  control,
  errors,
  disabled,
}: {
  name: Path<T>
  htmlId: string
  label: string
  items: TargetCatalogItem[]
  control: Control<T>
  errors: FieldErrors<T>
  disabled?: boolean
}) {
  return (
    <Field>
      <FieldLabel htmlFor={htmlId} required>
        {label}
      </FieldLabel>
      <Controller
        name={name}
        control={control}
        render={({ field }) => (
          <SearchSelect<TargetCatalogItem>
            id={htmlId}
            multiple
            value={(field.value as string[] | undefined) ?? []}
            onChange={(v) => field.onChange((v as string[] | null) ?? [])}
            options={items}
            isDisabled={disabled}
            invalid={!!errorMessage(errors, name)}
            placeholder='Seleccionar uno o varios…'
            getOptionLabel={(item) => item.name ?? ''}
          />
        )}
      />
      <FieldError>{errorMessage(errors, name)}</FieldError>
    </Field>
  )
}

export function SingleTargetSelector<T extends FieldValues>({
  control,
  register,
  errors,
  targetType,
  targetUnitId,
  targetUnitIds,
  catalogs,
  disabled,
  idPrefix,
  singleUnitLabel,
  onSingleUnitLabelChange,
  onUnitSelect,
  onUnitIdsChange,
  availableTypes = SINGLE_TARGET_VISIBLE_TYPES,
  excludedTypes,
  typeLabels = SINGLE_TARGET_TYPE_LABELS,
  names = DEFAULT_SINGLE_TARGET_NAMES,
  sectionLabel = 'Alcance — ¿a quiénes aplica?',
  floorIsMulti = false,
  previewSelector = null,
  previewFetcher,
  previewNoun = 'unidades incluidas',
  previewScope,
}: SingleTargetSelectorProps<T>) {
  const visibleTypes = excludedTypes?.length
    ? availableTypes.filter((type) => !excludedTypes.includes(type))
    : availableTypes

  return (
    <>
      <Field>
        <FieldLabel required>{sectionLabel}</FieldLabel>
        <Controller
          name={names.targetType as Path<T>}
          control={control}
          render={({ field }) => {
            const current = field.value as string
            return (
              <RadioGroup
                value={current}
                onValueChange={field.onChange}
                disabled={disabled}
                className='grid grid-cols-1 gap-2 sm:grid-cols-2'
              >
                {visibleTypes.map((type) => {
                  const selected = current === type
                  const Icon = SINGLE_TARGET_TYPE_ICONS[type] ?? Building2
                  const optionId = `${idPrefix}-target-${type}`
                  return (
                    <label
                      key={type}
                      htmlFor={optionId}
                      className={cn(
                        'flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors',
                        selected
                          ? 'border-[var(--green-deep)] bg-[var(--green-bg)] ring-1 ring-[var(--green-deep)]'
                          : 'border-border bg-card hover:border-foreground/25 hover:bg-accent/40',
                        disabled && 'pointer-events-none opacity-60'
                      )}
                    >
                      <RadioGroupItem id={optionId} value={type} className='mt-0.5 shrink-0' />
                      <span
                        className={cn(
                          'flex size-8 flex-none items-center justify-center rounded-lg',
                          selected
                            ? 'bg-[var(--green-deep)] text-white'
                            : 'bg-muted text-muted-foreground'
                        )}
                      >
                        <Icon className='size-4' aria-hidden />
                      </span>
                      <span className='min-w-0'>
                        <span className='block text-[13px] font-medium leading-tight'>
                          {typeLabels[type]}
                        </span>
                        {SINGLE_TARGET_TYPE_HINTS[type] && (
                          <span className='mt-0.5 block text-[11.5px] leading-snug text-[var(--ink-3)]'>
                            {SINGLE_TARGET_TYPE_HINTS[type]}
                          </span>
                        )}
                      </span>
                    </label>
                  )
                })}
              </RadioGroup>
            )
          }}
        />
      </Field>

      {targetType === 'by_type' && (
        <TargetCatalogMultiSelect
          name={names.unitTypeIds as Path<T>}
          htmlId={`${idPrefix}-${names.unitTypeIds}`}
          label='Tipos de unidad'
          items={catalogs.unitTypes}
          control={control}
          errors={errors}
          disabled={disabled}
        />
      )}

      {targetType === 'by_tower' && (
        <TargetCatalogMultiSelect
          name={names.towerIds as Path<T>}
          htmlId={`${idPrefix}-${names.towerIds}`}
          label='Torres'
          items={catalogs.towers}
          control={control}
          errors={errors}
          disabled={disabled}
        />
      )}

      {targetType === 'by_section' && (
        <TargetCatalogMultiSelect
          name={names.sectionIds as Path<T>}
          htmlId={`${idPrefix}-${names.sectionIds}`}
          label='Secciones'
          items={catalogs.sections ?? []}
          control={control}
          errors={errors}
          disabled={disabled}
        />
      )}

      {targetType === 'by_feature' && (
        <TargetCatalogMultiSelect
          name={names.featureIds as Path<T>}
          htmlId={`${idPrefix}-${names.featureIds}`}
          label='Características'
          items={catalogs.features}
          control={control}
          errors={errors}
          disabled={disabled}
        />
      )}

      {/* by_floor — fuera del set visible; rama viva solo para datos legacy. El campo `floor`
          es STRING (un piso, cobranza) o `string[]` (varios pisos, chat): `floorIsMulti` elige
          la forma para no romper el schema Zod al guardar un canal legacy. */}
      {targetType === 'by_floor' && (
        <Field className='max-w-[260px]'>
          <FieldLabel htmlFor={`${idPrefix}-floor`} required>
            {floorIsMulti ? 'Pisos' : 'Piso'}
          </FieldLabel>
          {floorIsMulti ? (
            <Controller
              name={names.floor as Path<T>}
              control={control}
              render={({ field }) => (
                <Input
                  id={`${idPrefix}-floor`}
                  placeholder='1, 2, 3'
                  value={
                    Array.isArray(field.value)
                      ? field.value.join(', ')
                      : ((field.value as string | undefined) ?? '')
                  }
                  onChange={(e) =>
                    field.onChange(
                      e.target.value
                        .split(',')
                        .map((piso) => piso.trim())
                        .filter(Boolean)
                    )
                  }
                  disabled={disabled}
                />
              )}
            />
          ) : (
            <Input
              id={`${idPrefix}-floor`}
              placeholder='5'
              {...register(names.floor as Path<T>)}
              disabled={disabled}
            />
          )}
          <FieldError>{errorMessage(errors, names.floor)}</FieldError>
        </Field>
      )}

      {/* single_unit — fuera del set visible; rama viva solo para datos legacy. */}
      {targetType === 'single_unit' && (
        <Field>
          <FieldLabel htmlFor={`${idPrefix}-unit`} required>
            Unidad
          </FieldLabel>
          <UnitCombobox
            id={`${idPrefix}-unit`}
            value={targetUnitId}
            initialLabel={singleUnitLabel || undefined}
            disabled={disabled}
            onSelect={(unit) => {
              onUnitSelect(unit.id)
              onSingleUnitLabelChange(unit.full_identifier ?? unit.number ?? '')
            }}
          />
          <FieldError>{errorMessage(errors, names.unitId)}</FieldError>
        </Field>
      )}

      {targetType === 'manual' && (
        <ManualUnitPicker
          selected={targetUnitIds}
          onChange={onUnitIdsChange}
          error={errorMessage(errors, names.unitIds)}
          disabled={disabled}
        />
      )}

      {/* Vista previa del conteo de destinatarios: al FINAL para reflejar la seleccion
          completa. Solo se monta cuando el consumidor pasa `previewFetcher`, asi el hook
          `useQuery` de `TargetPreview` nunca se llama de forma condicional. */}
      {previewFetcher && (
        <TargetPreview
          previewScope={previewScope ?? idPrefix}
          targetType={targetType}
          previewSelector={previewSelector}
          fetcher={previewFetcher}
          noun={previewNoun}
        />
      )}
    </>
  )
}
