import { cn } from '@/lib/utils'
import { Check } from 'lucide-react'
import { type ReactNode, useEffect, useMemo, useRef } from 'react'
import ReactSelect, {
  components as selectComponents,
  type ClassNamesConfig,
  type GroupBase,
  type MultiValue,
  type OptionProps,
  type SelectComponentsConfig,
  type SingleValue,
  type ValueContainerProps,
} from 'react-select'
import AsyncSelect from 'react-select/async'

/**
 * Forma mínima de una opción: las claves que el componente necesita para
 * renderizar y para mapear value↔id. Cualquier `{ id, name, ...extra }`
 * (ej. `ICatalogItem`, `IEntity` con `name`) la satisface estructuralmente.
 */
export interface SearchSelectItem {
  id: string
  /** Label por defecto. Opcional para aceptar tipos basados en `IEntity` (name opcional);
   *  el `getOptionLabel` por defecto cae a '' si falta. Pasá un `getOptionLabel` custom si el
   *  label vive en otra propiedad. */
  name?: string
}

interface SearchSelectProps<T extends SearchSelectItem> {
  /** id (single) o ids (multiple) seleccionados. Igual que el `value` de un `<Select>` shadcn. */
  value?: string | string[] | null
  /** Devuelve id (single) o ids (multiple). Drop-in con `field.onChange` de RHF. */
  onChange: (_value: string | string[] | null) => void
  /** Data para búsqueda en CLIENTE (sobre lo ya cargado). Excluyente con `loadOptions`. */
  options?: T[]
  /**
   * Búsqueda REMOTA (typeahead). Si se pasa, el componente usa AsyncSelect con
   * debounce. Para que un valor ya seleccionado muestre su label en un form de
   * edición, pasá también `selectedOptions` con esa(s) opción(es).
   */
  loadOptions?: (_term: string) => Promise<T[]>
  /** Opciones ya conocidas del `value` actual (sólo modo remoto, para pintar el label). */
  selectedOptions?: T[]
  multiple?: boolean
  isClearable?: boolean
  isDisabled?: boolean
  isLoading?: boolean
  invalid?: boolean
  placeholder?: string
  /** id del input para asociar un `<label htmlFor>`. */
  id?: string
  getOptionLabel?: (_option: T) => string
  getOptionValue?: (_option: T) => string
  noOptionsMessage?: string
  loadingMessage?: string
  /** Portalea el menú a `document.body` (evita clipping dentro de Sheets/Dialogs). Default true. */
  menuPortal?: boolean
  /** Se dispara al abrir el menú (react-select `onMenuOpen`). Útil para cargar catálogos lazy al abrir. */
  onMenuOpen?: () => void
  /** Render custom del label de cada opción (passthrough de react-select). Ej: punto de color + texto. */
  formatOptionLabel?: (_option: T) => ReactNode
  /** En modo `multiple`, muestra "N seleccionado(s)" en el control en vez de los chips. Default false. */
  collapseSelectedToCount?: boolean
  /**
   * Si `options` trae UNA sola opción y todavía no hay `value`, la selecciona sola.
   * Para campos OBLIGATORIOS con una única respuesta posible (ej. el residente que
   * tiene una sola unidad): elegir de una lista de uno no es una decisión, es un
   * trámite. No aplica en `multiple` ni en modo remoto (`loadOptions` no conoce el
   * total), y NO se usa en filtros: ahí "Todos" sigue siendo lo correcto.
   */
  autoSelectSingle?: boolean
}

/** classNames que replican el look de los átomos shadcn (`select.tsx`). */
function buildClassNames<T extends SearchSelectItem>(
  invalid: boolean,
  wrapValues: boolean
): ClassNamesConfig<T, boolean, GroupBase<T>> {
  return {
    control: ({ isFocused, isDisabled }) =>
      cn(
        'flex w-full items-center rounded-md border bg-card px-3 py-1 text-sm transition-[color,box-shadow] outline-none min-h-9',
        isFocused ? 'border-ring ring-[3px] ring-ring/50' : 'border-input',
        invalid && 'border-destructive ring-destructive/20',
        isDisabled && 'cursor-not-allowed opacity-50'
      ),
    // En chips (multi sin colapsar) los valores envuelven; en cualquier otro modo el
    // contenido va en UNA línea y el texto largo se trunca con "…" — así el control
    // nunca crece de alto ni de ancho (los filtros viven en wrappers de ancho fijo).
    // `!flex-nowrap`: aun con `unstyled`, react-select inyecta `flex-wrap: wrap` en el
    // ValueContainer vía emotion DESPUÉS del stylesheet de Tailwind, así que sin el
    // `!important` el input de búsqueda salta a una segunda línea y el control crece.
    valueContainer: () =>
      cn(
        'flex min-w-0 flex-1 items-center gap-1 py-0.5',
        wrapValues ? 'flex-wrap' : '!flex-nowrap overflow-hidden'
      ),
    placeholder: () => 'truncate text-muted-foreground text-sm',
    singleValue: () => 'truncate text-sm text-foreground',
    input: () => 'text-sm text-foreground [&_input:focus]:ring-0 [&_input]:shadow-none',
    multiValue: () => 'inline-flex items-center rounded-sm bg-secondary text-secondary-foreground',
    multiValueLabel: () => 'px-1.5 py-0.5 text-xs',
    multiValueRemove: () =>
      'flex items-center rounded-r-sm px-1 hover:bg-destructive/20 hover:text-destructive',
    indicatorsContainer: () => 'flex items-center gap-1',
    indicatorSeparator: () => 'hidden',
    dropdownIndicator: () => 'text-muted-foreground/70 px-1',
    clearIndicator: () => 'text-muted-foreground/70 hover:text-foreground cursor-pointer px-1',
    loadingIndicator: () => 'text-muted-foreground px-1',
    menu: () =>
      'mt-1 overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md',
    menuList: () => 'p-1 max-h-60 overflow-y-auto',
    // El menú se portalea a document.body. Dentro de un Sheet/Dialog de Radix (z-50) hay
    // que ganarle al stacking: react-select fija un z-index:1 vía su clase emotion, así que
    // el `z-*` normal pierde por orden de stylesheet → usamos `!z-[100]` (!important) para
    // que el menú quede POR ENCIMA del modal y sus opciones sean clickeables.
    menuPortal: () => '!z-[100] pointer-events-auto',
    option: ({ isFocused, isSelected, isDisabled }) =>
      cn(
        'relative flex cursor-default select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none',
        isFocused && 'bg-accent text-accent-foreground',
        isSelected && 'font-medium',
        isDisabled && 'pointer-events-none opacity-50'
      ),
    noOptionsMessage: () => 'px-2 py-2 text-center text-sm text-muted-foreground',
    loadingMessage: () => 'px-2 py-2 text-center text-sm text-muted-foreground',
    group: () => 'py-1',
    groupHeading: () => 'px-2 py-1.5 text-xs text-muted-foreground',
  }
}

/**
 * Select unificado de la app (estilo `@ng-select`): SIEMPRE con búsqueda.
 * - Por defecto filtra en CLIENTE sobre `options` (la data ya cargada).
 * - Si pasás `loadOptions`, hace búsqueda REMOTA con debounce (typeahead).
 * Habla en IDs por fuera (drop-in con el patrón RHF/`field`), se ve 100% shadcn.
 *
 * @example
 * // catálogo (cliente):
 * <SearchSelect value={field.value} onChange={field.onChange}
 *   options={catalogs[CatalogType.UNIT]} placeholder="Buscar unidad…" />
 * // remoto (typeahead):
 * <SearchSelect value={field.value} onChange={field.onChange}
 *   loadOptions={(t) => UserResource.searchByTerm(t)} selectedOptions={current} multiple />
 */
export function SearchSelect<T extends SearchSelectItem>({
  value,
  onChange,
  options,
  loadOptions,
  selectedOptions,
  multiple = false,
  // Default false para igualar el `<Select>` shadcn (un campo requerido no se "limpia"
  // accidentalmente). Pasá `isClearable` en campos OPCIONALES donde vaciar tiene sentido.
  isClearable = false,
  isDisabled = false,
  isLoading = false,
  invalid = false,
  placeholder = 'Seleccionar…',
  id,
  getOptionLabel = (option) => option.name ?? '',
  getOptionValue = (option) => option.id,
  noOptionsMessage = 'Sin resultados',
  loadingMessage = 'Cargando…',
  menuPortal = true,
  onMenuOpen,
  formatOptionLabel,
  collapseSelectedToCount = false,
  autoSelectSingle = false,
}: SearchSelectProps<T>) {
  // Auto-selección con una sola opción. El ref lo deja como evento de UNA vez: si
  // el usuario limpia el campo a mano (isClearable), no se lo volvemos a llenar.
  const autoSelectedRef = useRef(false)
  useEffect(() => {
    if (!autoSelectSingle || multiple || autoSelectedRef.current) return
    if (value) return
    if (options?.length !== 1) return
    autoSelectedRef.current = true
    onChange(getOptionValue(options[0]))
  }, [autoSelectSingle, multiple, value, options, onChange, getOptionValue])

  // Índice id→opción a partir de la data de cliente + opciones ya conocidas (modo remoto).
  const optionsById = useMemo(() => {
    const map = new Map<string, T>()
    for (const option of options ?? []) map.set(getOptionValue(option), option)
    for (const option of selectedOptions ?? []) map.set(getOptionValue(option), option)
    return map
  }, [options, selectedOptions, getOptionValue])

  // Resuelve el value externo (id/ids) a la(s) opción(es) que react-select espera.
  const selectValue = useMemo(() => {
    const resolve = (rawId: string): T =>
      optionsById.get(rawId) ?? ({ id: rawId, name: rawId } as T)
    if (multiple) {
      const ids = Array.isArray(value) ? value : value ? [value] : []
      return ids.map(resolve)
    }
    const single = Array.isArray(value) ? value[0] : value
    return single ? resolve(single) : null
  }, [value, multiple, optionsById])

  const handleChange = (selected: MultiValue<T> | SingleValue<T>) => {
    if (multiple) {
      onChange((selected as MultiValue<T>).map(getOptionValue))
      return
    }
    const single = selected as SingleValue<T>
    onChange(single ? getOptionValue(single) : null)
  }

  // Debounce de la búsqueda remota (react-select no debouncea por defecto).
  const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const debouncedLoad = useMemo(() => {
    if (!loadOptions) return undefined
    return (term: string) =>
      new Promise<T[]>((resolve) => {
        if (debounceRef.current) clearTimeout(debounceRef.current)
        debounceRef.current = setTimeout(() => {
          loadOptions(term)
            .then(resolve)
            .catch(() => resolve([]))
        }, 300)
      })
  }, [loadOptions])

  const portalTarget = menuPortal && typeof document !== 'undefined' ? document.body : undefined
  const wrapValues = multiple && !collapseSelectedToCount
  const classNames = useMemo(() => buildClassNames<T>(invalid, wrapValues), [invalid, wrapValues])

  // En multiselect mostramos un check (✓) en las opciones ya elegidas (las opciones
  // seleccionadas siguen visibles en el menú porque `hideSelectedOptions: false`). En
  // modo single no se altera el render: se conserva el estilo unstyled de react-select.
  const customComponents = useMemo(() => {
    if (!multiple) return undefined
    // `boolean` (no `true`): el select se tipa con `isMulti: boolean`, así que su
    // SelectComponents espera props con `boolean`. Tiparlo `true` rompe tsc.
    const comps: Partial<SelectComponentsConfig<T, boolean, GroupBase<T>>> = {
      Option: (props: OptionProps<T, boolean, GroupBase<T>>) => (
        <selectComponents.Option {...props}>
          <span className='flex w-full items-center justify-between gap-2'>
            <span className='truncate'>{props.children}</span>
            {props.isSelected && <Check className='size-4 shrink-0 text-foreground' aria-hidden />}
          </span>
        </selectComponents.Option>
      ),
    }
    // Colapsa las selecciones a "N seleccionado(s)" en el control (en vez de chips),
    // conservando el input de búsqueda tecleable. Mantiene el placeholder cuando no hay
    // selección; mientras el usuario escribe, muestra sólo lo tecleado.
    if (collapseSelectedToCount) {
      comps.ValueContainer = (props: ValueContainerProps<T, boolean, GroupBase<T>>) => {
        const count = props.getValue().length
        const isTyping = Boolean(props.selectProps.inputValue)
        const nodes: ReactNode[] = Array.isArray(props.children) ? props.children : [props.children]
        const valueNode = nodes.length > 1 ? nodes[0] : null
        const inputNode = nodes[nodes.length - 1]
        return (
          <selectComponents.ValueContainer {...props}>
            {count > 0 ? (
              isTyping ? null : (
                <span className='whitespace-nowrap text-foreground text-sm'>
                  {count} seleccionado{count === 1 ? '' : 's'}
                </span>
              )
            ) : (
              valueNode
            )}
            {inputNode}
          </selectComponents.ValueContainer>
        )
      }
    }
    return comps
  }, [multiple, collapseSelectedToCount])

  const shared = {
    inputId: id,
    isMulti: multiple,
    isClearable,
    isDisabled,
    isLoading,
    placeholder,
    value: selectValue,
    onChange: handleChange,
    onMenuOpen,
    formatOptionLabel: formatOptionLabel ? (option: T) => formatOptionLabel(option) : undefined,
    getOptionLabel,
    getOptionValue,
    unstyled: true,
    classNames,
    components: customComponents,
    menuPortalTarget: portalTarget,
    closeMenuOnSelect: !multiple,
    hideSelectedOptions: false,
    noOptionsMessage: () => noOptionsMessage,
    loadingMessage: () => loadingMessage,
    'aria-invalid': invalid || undefined,
  } as const

  if (debouncedLoad) {
    return (
      <AsyncSelect<T, boolean, GroupBase<T>>
        {...shared}
        loadOptions={debouncedLoad}
        defaultOptions
        cacheOptions
      />
    )
  }

  return <ReactSelect<T, boolean, GroupBase<T>> {...shared} options={options} />
}
