import { Input } from '@/components/ui/input'
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import type { IUser } from '@/modules/user/schema'
import { UserResource } from '@/modules/user/services/UserResource'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, Loader2, Search, UserCheck, X } from 'lucide-react'
import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'

export interface UserComboboxProps {
  value?: string
  /** Nombre a mostrar cuando ya hay un user_id seleccionado (modo edición) */
  initialLabel?: string
  onSelect: (_user: IUser) => void
  /**
   * Quita la persona elegida; el padre DEBE vaciar aquí su `user_id`. Sin este
   * callback la ficha no ofrece la ✕ y la selección queda fija (es lo correcto
   * en formularios donde la persona no se puede cambiar, ej. edición de una
   * asociación existente).
   */
  onClear?: () => void
  placeholder?: string
  disabled?: boolean
  /** Pinta el campo en rojo cuando el formulario marca error en este campo. */
  invalid?: boolean
  id?: string
}

/**
 * Buscador de personas (POST /user/search) — §6.3.4 "Buscar persona existente".
 *
 * Regla de oro: **lo tecleado no es la selección**. Sólo elegir del menú guarda
 * la persona. Para que eso no se pueda malinterpretar, el componente tiene dos
 * modos excluyentes:
 *
 * - Sin selección → input de búsqueda. Al salir del campo el texto se BORRA,
 *   así nunca queda un nombre escrito que aparente estar guardado.
 * - Con selección → ficha (nombre + ✕), sin input editable.
 *
 * Enter con el menú abierto elige el resultado resaltado y NO envía el
 * formulario: teclear el nombre y pulsar Enter era la forma más fácil de
 * disparar un submit con el campo vacío.
 */
export function UserCombobox({
  value,
  initialLabel,
  onSelect,
  onClear,
  placeholder,
  disabled,
  invalid,
  id,
}: UserComboboxProps) {
  const [term, setTerm] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  // Gate de una sola vez: nada se pide hasta que el usuario abre el campo por
  // primera vez (regla de infra §2.12 — no hay fetch al montar). Una vez abierto
  // queda habilitado para que el typeahead siga respondiendo a cada término.
  const [hasOpened, setHasOpened] = useState(false)
  const [highlightedIndex, setHighlightedIndex] = useState(0)
  const [pickedLabel, setPickedLabel] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()

  const selectedLabel = value ? (pickedLabel ?? initialLabel ?? 'Persona seleccionada') : null

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(term.trim()), 300)
    return () => clearTimeout(timer)
  }, [term])

  // Con término vacío Orion arma `like '%%'`, así que devuelve la primera página:
  // al enfocar, el usuario ya ve personas en vez de una caja muda que parece rota.
  const { data, isFetching } = useQuery({
    queryKey: ['user-combobox', debounced],
    queryFn: () => UserResource.searchByTerm(debounced),
    enabled: hasOpened && !disabled && !value,
    staleTime: 30 * 1000,
  })

  // Referencia estable: `data ?? []` inline crearía un array nuevo por render.
  const results = useMemo(() => data ?? [], [data])

  // El resaltado se DERIVA (no se sincroniza con un efecto): si la nueva búsqueda
  // trae menos resultados, el índice guardado se acota al último disponible.
  const lastIndex = Math.max(results.length - 1, 0)
  const activeIndex = Math.min(highlightedIndex, lastIndex)

  const openMenu = () => {
    if (disabled) return
    setHasOpened(true)
    setOpen(true)
  }

  const handlePick = (user: IUser) => {
    setPickedLabel(user.full_name ?? user.name)
    setTerm('')
    setOpen(false)
    onSelect(user)
  }

  const handleClear = () => {
    setPickedLabel(null)
    setTerm('')
    onClear?.()
    // El re-render ya ocurrió cuando corre el rAF, así que el input existe.
    requestAnimationFrame(() => inputRef.current?.focus())
  }

  const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      openMenu()
      setHighlightedIndex(Math.min(activeIndex + 1, lastIndex))
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlightedIndex(Math.max(activeIndex - 1, 0))
      return
    }
    if (event.key === 'Escape') {
      setOpen(false)
      return
    }
    if (event.key === 'Enter' && open) {
      event.preventDefault()
      const candidate = results[activeIndex]
      if (candidate) {
        handlePick(candidate)
      }
    }
  }

  if (selectedLabel) {
    return (
      <div
        id={id}
        className={cn(
          'flex min-h-9 w-full items-center gap-2 rounded-md border border-[var(--green)] bg-card px-3 py-1 text-sm shadow-xs',
          invalid && 'border-destructive',
          disabled && 'cursor-not-allowed opacity-50'
        )}
      >
        <UserCheck className='size-4 shrink-0 text-[var(--green)]' aria-hidden />
        <span className='min-w-0 flex-1 truncate text-foreground'>{selectedLabel}</span>
        {onClear && !disabled && (
          <button
            type='button'
            onClick={handleClear}
            aria-label={`Quitar a ${selectedLabel}`}
            className='shrink-0 rounded-sm p-0.5 text-[var(--ink-3)] transition-colors hover:text-destructive'
          >
            <X className='size-4' />
          </button>
        )}
      </div>
    )
  }

  const emptyMessage = term.trim()
    ? `No encontramos a «${term.trim()}». Revisa cómo se escribe, o registra antes a la persona.`
    : 'Sin resultados'

  // El menú abre aunque no haya resultados: así puede decir "Buscando…" o "Sin
  // resultados" en vez de no hacer nada, que es lo que se leía como un error.
  return (
    <Popover open={open && !disabled} onOpenChange={setOpen}>
      <PopoverAnchor asChild>
        <div className='relative'>
          <Search
            className='pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]'
            aria-hidden
          />
          <Input
            id={id}
            ref={inputRef}
            value={term}
            disabled={disabled}
            placeholder={placeholder ?? 'Buscar por nombre, teléfono o correo…'}
            className='pl-9 pr-9'
            aria-invalid={invalid || undefined}
            role='combobox'
            aria-expanded={open}
            aria-controls={listboxId}
            aria-autocomplete='list'
            aria-activedescendant={
              open && results[activeIndex] ? `${listboxId}-${activeIndex}` : undefined
            }
            onFocus={openMenu}
            onChange={(e) => {
              setTerm(e.target.value)
              setHighlightedIndex(0)
              openMenu()
            }}
            onKeyDown={handleKeyDown}
            // Salir sin elegir borra lo tecleado: un nombre escrito que se queda
            // en pantalla se lee como "ya quedó guardado" y no lo está.
            onBlur={() => {
              setOpen(false)
              setTerm('')
            }}
            autoComplete='off'
          />
          {isFetching ? (
            <Loader2
              className='absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-[var(--ink-4)]'
              aria-hidden
            />
          ) : (
            <ChevronDown
              className='pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]'
              aria-hidden
            />
          )}
        </div>
      </PopoverAnchor>
      <PopoverContent
        align='start'
        className='w-[var(--radix-popover-trigger-width,320px)] p-0'
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <div id={listboxId} role='listbox' className='max-h-64 overflow-y-auto p-1'>
          {results.length === 0 ? (
            <p className='px-3 py-2 text-[13px] text-[var(--ink-3)]'>
              {isFetching ? 'Buscando…' : emptyMessage}
            </p>
          ) : (
            results.map((user, index) => (
              <button
                key={user.id}
                id={`${listboxId}-${index}`}
                role='option'
                aria-selected={index === activeIndex}
                type='button'
                // Sin esto el clic quita el foco del input antes de registrarse y
                // el `onBlur` cerraría el menú: la selección nunca ocurriría.
                onMouseDown={(e) => e.preventDefault()}
                onMouseEnter={() => setHighlightedIndex(index)}
                onClick={() => handlePick(user)}
                className={cn(
                  'flex w-full flex-col rounded-[8px] px-3 py-2 text-left transition-colors',
                  index === activeIndex && 'bg-[var(--surface-2)]'
                )}
              >
                <span className='text-[13.5px] font-medium text-foreground'>
                  {user.full_name ?? user.name}
                </span>
                <span className='text-[12px] text-[var(--ink-3)]'>
                  {[user.phone, user.email].filter(Boolean).join(' · ') || 'Sin contacto'}
                </span>
              </button>
            ))
          )}
        </div>
        <p className='border-t border-border px-3 py-2 text-[11.5px] leading-snug text-[var(--ink-4)]'>
          Elige una persona de la lista: escribir el nombre no la selecciona.
        </p>
      </PopoverContent>
    </Popover>
  )
}
