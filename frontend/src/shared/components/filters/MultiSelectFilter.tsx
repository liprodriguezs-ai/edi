import { SearchSelect } from '@/shared/components/SearchSelect'
import { cn } from '@/lib/utils'
import { useId, useMemo, useRef } from 'react'

export interface MultiSelectOption {
  value: string
  label: string
  color?: string
}

/** Forma interna que satisface `SearchSelectItem` ({ id, name }). */
interface FilterItem {
  id: string
  name: string
  color?: string
}

interface MultiSelectFilterProps {
  label: string
  options: MultiSelectOption[]
  value: string[]
  onChange: (next: string[]) => void
  placeholder?: string
  /** Aceptado por compatibilidad con los call sites; el control ya es compacto siempre. */
  compact?: boolean
  /**
   * Se dispara la PRIMERA vez que se abre el menú (gated a una sola llamada). Se usa para
   * cargar catálogos lazy al abrir el dropdown sin acoplar el filtro a ningún hook de datos.
   */
  onFirstOpen?: () => void
  /** Muestra "Cargando…" mientras el catálogo (lazy) carga. */
  isLoading?: boolean
  /** Override del ancho fijo por defecto (`w-48`) desde el call site. */
  className?: string
}

/**
 * Filtro multi-select de las listas. Por dentro usa `SearchSelect` (react-select, ya instalado):
 * la búsqueda ocurre en el PROPIO control y las selecciones se muestran como "N seleccionados"
 * (sin chips). Mantiene el contrato de props para no tocar los call sites.
 */
export function MultiSelectFilter({
  label,
  options,
  value,
  onChange,
  placeholder = 'Todos',
  onFirstOpen,
  isLoading = false,
  className,
}: MultiSelectFilterProps) {
  const openedRef = useRef(false)
  const inputId = useId()

  const handleMenuOpen = () => {
    if (openedRef.current) return
    openedRef.current = true
    onFirstOpen?.()
  }

  const items = useMemo<FilterItem[]>(
    () => options.map((option) => ({ id: option.value, name: option.label, color: option.color })),
    [options]
  )

  return (
    <div className={cn('flex w-full flex-col gap-1 sm:w-48', className)}>
      <label htmlFor={inputId} className='text-xs text-muted-foreground font-medium'>
        {label}
      </label>
      <SearchSelect<FilterItem>
        id={inputId}
        multiple
        collapseSelectedToCount
        isClearable
        value={value}
        onChange={(next) => onChange(Array.isArray(next) ? next : next ? [next] : [])}
        options={items}
        formatOptionLabel={(option) =>
          option.color ? (
            <span className='flex items-center gap-2'>
              <span
                className='h-2.5 w-2.5 rounded-full border'
                style={{ backgroundColor: option.color }}
                aria-hidden
              />
              <span className='truncate'>{option.name}</span>
            </span>
          ) : (
            option.name
          )
        }
        placeholder={placeholder}
        isLoading={isLoading}
        onMenuOpen={handleMenuOpen}
        noOptionsMessage='Sin opciones'
        loadingMessage='Cargando…'
      />
    </div>
  )
}
