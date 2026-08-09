import { Input } from '@/components/ui/input'
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover'
import type { IUnit } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Search } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'

async function searchUnits(term: string): Promise<IUnit[]> {
  const { data } = await UnitResource.search({
    limit: 8,
    search: { value: term },
    sort: [{ field: 'number', direction: 'asc' }],
  })
  return data
}

export interface UnitComboboxProps {
  value?: string
  initialLabel?: string
  onSelect: (_unit: IUnit) => void
  placeholder?: string
  disabled?: boolean
  id?: string
  /** name del input; default 'unit-search'. Evita el warning de DevTools (campo sin id/name). */
  name?: string
}

/** Buscador de unidades (POST /unit/search) con dropdown de resultados. */
export function UnitCombobox({
  value,
  initialLabel,
  onSelect,
  placeholder,
  disabled,
  id,
  name = 'unit-search',
}: UnitComboboxProps) {
  const [term, setTerm] = useState(initialLabel ?? '')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  // Gate de una sola vez: nada se pide hasta que el usuario abre el campo por
  // primera vez (regla de infra §2.12 — no hay fetch al montar). Una vez abierto
  // queda habilitado para que el typeahead siga respondiendo a cada término.
  const [hasOpened, setHasOpened] = useState(false)
  const skipNextSearch = useRef(Boolean(initialLabel))

  useEffect(() => {
    if (skipNextSearch.current) {
      skipNextSearch.current = false
      return
    }
    const timer = setTimeout(() => setDebounced(term.trim()), 300)
    return () => clearTimeout(timer)
  }, [term])

  // Con término vacío Orion arma `like '%%'`, así que devuelve la primera página
  // ordenada por número: al enfocar, el usuario ya ve opciones en vez de una caja
  // muda que parece rota.
  const { data: results = [], isFetching } = useQuery({
    queryKey: ['unit-combobox', debounced],
    queryFn: () => searchUnits(debounced),
    enabled: hasOpened && !disabled,
    staleTime: 30 * 1000,
  })

  const openMenu = () => {
    if (disabled) return
    setHasOpened(true)
    setOpen(true)
  }

  const handlePick = (unit: IUnit) => {
    skipNextSearch.current = true
    setTerm(unit.full_identifier ?? unit.number ?? '')
    setOpen(false)
    onSelect(unit)
  }

  // El menú abre aunque no haya resultados: así puede decir "Buscando…" o "Sin
  // resultados" en vez de no hacer nada, que es lo que se leía como un error.
  const isOpen = open && !disabled
  const listboxId = `${name}-listbox`

  return (
    <Popover open={isOpen} onOpenChange={setOpen}>
      <PopoverAnchor asChild>
        <div className='relative'>
          <Search
            className='pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]'
            aria-hidden
          />
          <Input
            id={id ?? name}
            name={name}
            role='combobox'
            aria-label={placeholder ?? 'Buscar unidad'}
            aria-expanded={isOpen}
            aria-controls={listboxId}
            aria-autocomplete='list'
            value={term}
            disabled={disabled}
            placeholder={placeholder ?? 'Buscar unidad por número…'}
            className={`bg-card pl-9 ${value ? 'border-[var(--green)]' : ''}`}
            onFocus={openMenu}
            onChange={(e) => {
              setTerm(e.target.value)
              openMenu()
            }}
            autoComplete='off'
          />
          {isFetching && (
            <Loader2
              className='absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-[var(--ink-4)]'
              aria-hidden
            />
          )}
        </div>
      </PopoverAnchor>
      <PopoverContent
        id={listboxId}
        role='listbox'
        align='start'
        className='w-[var(--radix-popover-trigger-width,320px)] p-1'
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        {results.length === 0 ? (
          <p className='px-3 py-2 text-[13px] text-[var(--ink-3)]'>
            {isFetching ? 'Buscando…' : 'Sin resultados'}
          </p>
        ) : (
          results.map((unit) => {
            const typeName = unit.unit_type?.name ?? ''
            // `full_identifier` ya incluye el tipo (ej. "Casa 1"); el badge de tipo solo
            // aporta info cuando caemos al fallback `number` (sin full_identifier).
            const showTypeSubtitle = !unit.full_identifier && typeName !== ''
            return (
              <button
                key={unit.id}
                type='button'
                role='option'
                aria-selected={unit.id === value}
                onClick={() => handlePick(unit)}
                className='flex w-full items-center justify-between rounded-[8px] px-3 py-2 text-left transition-colors hover:bg-[var(--surface-2)]'
              >
                <span className='text-[13.5px] font-medium text-foreground'>
                  {unit.full_identifier ?? unit.number}
                </span>
                {showTypeSubtitle && (
                  <span className='text-[12px] text-[var(--ink-3)]'>{typeName}</span>
                )}
              </button>
            )
          })
        )}
      </PopoverContent>
    </Popover>
  )
}
