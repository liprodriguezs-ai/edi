import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { ISearchParams } from '@/shared/interfaces/list.types'
import { Search, SlidersHorizontal, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { useCallback, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'

type SearchListSimpleFilter = {
  operator: string
  value: unknown
} & Record<string, unknown>

type SearchListComplexFilter = Record<string, { value: unknown } & Record<string, unknown>>

type SearchListFilters = Record<string, SearchListSimpleFilter | SearchListComplexFilter>

interface SearchListProps {
  paramsSearch?: ISearchParams
  filters?: SearchListFilters
  showSearchBar?: boolean
  searchAction?: boolean
  disabled?: boolean
  placeholder?: string
  defaultFiltersExpanded?: boolean
  toggleLabels?: {
    expand?: string
    collapse?: string
  }
  children?: ReactNode
  fieldsetChildren?: ReactNode
  onSearch: (_params: ISearchParams) => void
  onReset: (_data: { paramsSearch: ISearchParams; filters: SearchListFilters }) => void
}

interface SearchFormData {
  search: string
}

export const SearchList = ({
  paramsSearch = {},
  filters = {},
  showSearchBar = true,
  searchAction = true,
  disabled = false,
  placeholder = 'Buscar...',
  defaultFiltersExpanded = false,
  toggleLabels = { expand: 'Ver más filtros', collapse: 'Ver menos filtros' },
  children,
  fieldsetChildren,
  onSearch,
  onReset,
}: SearchListProps) => {
  const [filtersExpanded, setFiltersExpanded] = useState(defaultFiltersExpanded)
  const formRef = useRef<HTMLFormElement>(null)

  const { register, handleSubmit, watch, reset } = useForm<SearchFormData>({
    defaultValues: {
      search: '',
    },
  })

  const searchValue = watch('search')

  const dispatchSearch = useCallback(() => {
    const searchParams: ISearchParams = {
      ...paramsSearch,
      search: searchValue
        ? {
            value: searchValue,
            case_sensitive: false,
          }
        : null,
    }

    onSearch(searchParams)
  }, [paramsSearch, searchValue, onSearch])

  const handleSearch = useCallback(() => {
    dispatchSearch()
  }, [dispatchSearch])

  /** Limpia SOLO el texto de búsqueda (conserva filtros). Es la X dentro del input. */
  const clearSearch = useCallback(() => {
    reset({ search: '' })
    onSearch({ ...paramsSearch, search: null })
  }, [reset, onSearch, paramsSearch])

  const handleReset = useCallback(() => {
    // Reset form
    reset({ search: '' })

    // Reset search params
    const resetParams: ISearchParams = {
      ...paramsSearch,
      search: null,
    }

    // Reset filters - clear all form controls in the form
    const resetFilters: SearchListFilters = { ...filters }
    if (formRef.current) {
      const inputs = formRef.current.querySelectorAll('input, select, textarea, [name]')
      inputs.forEach((input) => {
        const element = input as HTMLInputElement
        const name = element.getAttribute('name')

        if (name && name !== 'search') {
          // Remove filter from search params
          if (resetParams.filters) {
            resetParams.filters = resetParams.filters.filter((item) => item.field !== name)
          }

          // Reset filter value
          const filterDef = resetFilters[name]
          if (!filterDef) return

          const operator = (filterDef as Partial<SearchListSimpleFilter>)?.operator
          const isSimpleFilter = typeof operator === 'string' && operator.length > 0

          if (isSimpleFilter) {
            resetFilters[name] = {
              ...(filterDef as SearchListSimpleFilter),
              value: null,
            } as SearchListSimpleFilter
            return
          }

          // Complex filter - iterate properties
          const complexFilter = filterDef as SearchListComplexFilter
          for (const key in complexFilter) {
            if (Object.prototype.hasOwnProperty.call(complexFilter, key)) {
              complexFilter[key] = {
                ...complexFilter[key],
                value: null,
              }
            }
          }
        }
      })
    }

    setFiltersExpanded(defaultFiltersExpanded)
    onReset({
      paramsSearch: resetParams,
      filters: resetFilters,
    })
  }, [paramsSearch, filters, reset, onReset, defaultFiltersExpanded])

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if (event.key === 'Enter') {
        event.preventDefault()
        handleSearch()
      }
    },
    [handleSearch]
  )

  return (
    <form
      ref={formRef}
      onSubmit={handleSubmit(handleSearch)}
      autoComplete='off'
      id='searchForm'
      className='w-full'
    >
      <fieldset disabled={disabled} className='border-0 p-0 m-0'>
        <div className='flex flex-col gap-2'>
          <div className='w-full'>
            <div className='flex flex-col gap-2'>
              {/* Content slot - filters, etc. */}

              {/* Search bar */}
              {showSearchBar && (
                <>
                  <div className='flex flex-wrap items-center gap-2'>
                    <div className='relative min-w-0 flex-1 basis-[min(100%,12rem)] max-w-xl'>
                      <Search className='pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground' />
                      <Input
                        {...register('search')}
                        placeholder={placeholder}
                        onKeyDown={handleKeyDown}
                        className='h-9 rounded-none border-border bg-card pl-10 pr-9'
                      />
                      {searchValue && (
                        <button
                          type='button'
                          onClick={clearSearch}
                          aria-label='Limpiar búsqueda'
                          className='absolute right-1.5 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center text-muted-foreground transition-colors hover:text-foreground'
                        >
                          <X className='size-4' />
                        </button>
                      )}
                    </div>

                    {(children || fieldsetChildren) && (
                      /* Toggle de filtros: icono junto al buscador, mobile Y desktop. */
                      <Button
                        type='button'
                        variant={filtersExpanded ? 'secondary' : 'outline'}
                        size='icon'
                        onClick={() => setFiltersExpanded((v) => !v)}
                        aria-expanded={filtersExpanded}
                        aria-label={
                          filtersExpanded
                            ? (toggleLabels?.collapse ?? 'Ocultar filtros')
                            : (toggleLabels?.expand ?? 'Mostrar filtros')
                        }
                        title={
                          filtersExpanded
                            ? (toggleLabels?.collapse ?? 'Ocultar filtros')
                            : (toggleLabels?.expand ?? 'Mostrar filtros')
                        }
                        className='size-9 rounded-none'
                      >
                        <SlidersHorizontal className='h-4 w-4' />
                      </Button>
                    )}

                    {/* Reset COMPLETO (búsqueda + filtros) solo cuando hay filtros;
                        en listas simples la X del input basta. */}
                    {searchAction && (children || fieldsetChildren) && (
                      <Button
                        type='button'
                        variant='outline'
                        onClick={handleReset}
                        className='h-9 rounded-none px-4'
                      >
                        Limpiar
                      </Button>
                    )}

                    {searchAction && (
                      <Button type='submit' className='h-9 gap-2 px-4'>
                        <Search className='h-4 w-4' />
                        <span className='hidden md:block'>Buscar</span>
                      </Button>
                    )}
                  </div>
                </>
              )}
            </div>
          </div>
          {(children || fieldsetChildren) && (
            /* CSS y no desmontaje: los inputs siguen montados para que handleReset
               pueda limpiarlos aunque estén colapsados. */
            <div className={filtersExpanded ? undefined : 'hidden'}>
              {children}
              {/* Fieldset content slot - additional form controls */}
              {fieldsetChildren}
            </div>
          )}
        </div>
      </fieldset>
    </form>
  )
}
