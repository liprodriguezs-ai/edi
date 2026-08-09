import { Input } from '@/components/ui/input'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { AnnouncementReader } from '@/modules/announcement/schema'
import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import { Pagination } from '@/shared/components/Pagination'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { IPagination } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'

const PER_PAGE = 20
const SEARCH_DEBOUNCE_MS = 300

type ReadFilter = 'all' | 'read' | 'unread'

const FILTERS: Array<{ value: ReadFilter; label: string }> = [
  { value: 'all', label: 'Todos' },
  { value: 'read', label: 'Leídos' },
  { value: 'unread', label: 'No leídos' },
]

// Etiquetas en español de la relación unidad↔residente (columna `unit_user.relationship`).
const RELATIONSHIP_LABELS: Record<string, string> = {
  owner: 'Propietario',
  co_owner: 'Copropietario',
  renter: 'Inquilino',
  family_resident: 'Familiar',
  billing_contact: 'Contacto de cobranza',
  board_member: 'Directiva',
}

function relationshipLabel(relationship: string | null): string {
  if (!relationship) return '—'
  return RELATIONSHIP_LABELS[relationship] ?? relationship
}

interface AnnouncementReadStatsProps {
  announcementId: string
  /** Sólo dispara las queries cuando el tab "Lecturas" está abierto (ahorro de infra). */
  enabled: boolean
}

/**
 * Fila de la tabla de lectores. Extiende `IEntity` para satisfacer `ResponsiveDataTable`
 * (necesita `id`); el `id` es sintético (`user:unit`) porque una persona puede aparecer en
 * varias unidades y los avisos a directiva no tienen unidad real (`unit.id === null`).
 */
interface ReaderRow extends AnnouncementReader, IEntity {}

function ReadStatusPill({ read }: { read: boolean }) {
  return <StatusPill tone={read ? 'g' : 'y'}>{read ? 'Leído' : 'No leído'}</StatusPill>
}

/**
 * Panel de estadísticas de lectura de un aviso (tab "Lecturas", modo edición). Cabecera con
 * los conteos agregados (a nivel PERSONA) + barra de progreso, y una tabla de lectores
 * server-paginada. El filtro (Todos/Leídos/No leídos) y la búsqueda por nombre/unidad
 * DISPARAN la query (server-side). Carga LAZY (gateada por `enabled`); sin polling.
 */
export function AnnouncementReadStats({ announcementId, enabled }: AnnouncementReadStatsProps) {
  const [status, setStatus] = useState<ReadFilter>('all')
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(PER_PAGE)

  // Debounce de la búsqueda para no disparar una request por cada tecla.
  useEffect(() => {
    const handle = setTimeout(() => setDebouncedSearch(search), SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(handle)
  }, [search])

  // Cambiar filtro/búsqueda vuelve a la primera página. Ajuste en RENDER (no en efecto):
  // es el patrón recomendado por React para "estado derivado de un cambio", sin renders en cascada.
  const filterKey = `${status}|${debouncedSearch}|${perPage}`
  const [prevFilterKey, setPrevFilterKey] = useState(filterKey)
  if (filterKey !== prevFilterKey) {
    setPrevFilterKey(filterKey)
    setPage(1)
  }

  // Conteos agregados — NO cambian con filtro/búsqueda, así que su query es independiente.
  const summaryQuery = useQuery({
    queryKey: ['announcement', 'read-summary', announcementId],
    queryFn: () => AnnouncementResource.readSummary(announcementId),
    enabled,
  })

  const readersQuery = useQuery({
    queryKey: ['announcement', 'readers', announcementId, status, debouncedSearch, page, perPage],
    queryFn: () =>
      AnnouncementResource.readers(announcementId, {
        status,
        search: debouncedSearch || undefined,
        page,
        per_page: perPage,
      }),
    enabled,
    placeholderData: keepPreviousData,
  })

  const rows = useMemo<ReaderRow[]>(
    () =>
      (readersQuery.data?.data ?? []).map((reader) => ({
        ...reader,
        id: `${reader.user.id}:${reader.unit.id ?? 'board'}`,
      })),
    [readersQuery.data]
  )

  const meta = readersQuery.data?.meta
  const pagination: IPagination | null = meta
    ? {
        page: meta.current_page,
        pages: meta.last_page,
        per_page: meta.per_page,
        total: meta.total,
        limit: meta.per_page,
        from: meta.from ?? null,
        to: meta.to ?? null,
      }
    : null

  const handlePageChanged = (nextPage: number, nextSize?: number) => {
    if (nextSize && nextSize !== perPage) {
      setPerPage(nextSize)
      setPage(1)
    } else {
      setPage(nextPage)
    }
  }

  const summary = summaryQuery.data
  const readPct = summary ? Math.min(100, Math.max(0, Math.round(summary.read_percentage))) : 0

  return (
    <div className='space-y-5'>
      {/* Cabecera con los conteos + barra de progreso */}
      <div className='rounded-[14px] border border-border bg-card p-5'>
        {summaryQuery.isError ? (
          <p className='text-sm text-destructive'>No se pudieron cargar los conteos de lectura.</p>
        ) : !summary ? (
          <p className='text-sm text-[var(--ink-3)]'>Cargando conteos…</p>
        ) : (
          <>
            <div className='flex flex-wrap items-end justify-between gap-3'>
              <div>
                <p className='text-[22px] font-semibold leading-none'>
                  {summary.read_people_count} de {summary.target_people_count}{' '}
                  <span className='text-[14px] font-normal text-[var(--ink-3)]'>residentes</span>
                </p>
                <p className='mt-1.5 flex items-center gap-1.5 text-[12.5px] text-[var(--ink-3)]'>
                  <Users className='size-3.5' />
                  {summary.target_units_count > 0 && (
                    <>
                      {summary.target_units_count}{' '}
                      {summary.target_units_count === 1
                        ? 'unidad alcanzada'
                        : 'unidades alcanzadas'}
                      {' · '}
                    </>
                  )}
                  {summary.unread_people_count} sin leer
                </p>
              </div>
              <span className='text-[24px] font-semibold tabular-nums text-[var(--green-deep)]'>
                {readPct}%
              </span>
            </div>
            <div className='mt-3 h-2 overflow-hidden rounded-full bg-[var(--surface-2)]'>
              <div
                className='h-full rounded-full bg-[var(--green)] transition-[width]'
                style={{ width: `${readPct}%` }}
              />
            </div>
          </>
        )}
      </div>

      {/* Filtros + búsqueda (server-side) */}
      <div className='flex flex-wrap items-center gap-3'>
        <div
          role='group'
          aria-label='Filtrar por estado de lectura'
          className='inline-flex rounded-full border border-border bg-card p-0.5'
        >
          {FILTERS.map((option) => (
            <button
              key={option.value}
              type='button'
              aria-pressed={status === option.value}
              onClick={() => setStatus(option.value)}
              className={`rounded-full px-3 py-1 text-[12.5px] font-medium transition-colors ${
                status === option.value
                  ? 'bg-[var(--green-bg)] text-[var(--green-deep)]'
                  : 'text-[var(--ink-3)] hover:text-[var(--ink-1)]'
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>
        <div className='relative min-w-[200px] flex-1'>
          <Search className='pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]' />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder='Buscar por residente o unidad…'
            className='pl-9'
            aria-label='Buscar por residente o unidad'
          />
        </div>
      </div>

      {/* Tabla de lectores */}
      {readersQuery.isLoading ? (
        <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando lectores…</p>
      ) : readersQuery.isError ? (
        <p className='py-12 text-center text-sm text-destructive'>
          No se pudo cargar la lista de lectores.
        </p>
      ) : rows.length === 0 ? (
        <p className='rounded-[14px] border border-border bg-card px-6 py-10 text-center text-sm text-[var(--ink-3)]'>
          No hay lectores que coincidan con el filtro.
        </p>
      ) : (
        <div className={readersQuery.isFetching ? 'opacity-60 transition-opacity' : undefined}>
          <ResponsiveDataTable<ReaderRow>
            rows={rows}
            breakpoint='md'
            header={
              <TableHeader>
                <TableRow>
                  <TableHead scope='col'>Unidad</TableHead>
                  <TableHead scope='col'>Residente</TableHead>
                  <TableHead scope='col' className='hidden md:table-cell'>
                    Relación
                  </TableHead>
                  <TableHead scope='col'>Estado</TableHead>
                  <TableHead scope='col' className='hidden lg:table-cell'>
                    Fecha
                  </TableHead>
                </TableRow>
              </TableHeader>
            }
            renderRow={(row) => (
              <TableRow key={row.id}>
                <TableCell className='font-medium'>{row.unit.full_identifier}</TableCell>
                <TableCell>{row.user.name}</TableCell>
                <TableCell className='hidden md:table-cell text-[var(--ink-3)]'>
                  {relationshipLabel(row.relationship)}
                </TableCell>
                <TableCell>
                  <ReadStatusPill read={row.read} />
                </TableCell>
                <TableCell className='hidden lg:table-cell text-[var(--ink-3)]'>
                  {row.read_at ? displayFormatDate(row.read_at, 'medium') : '—'}
                </TableCell>
              </TableRow>
            )}
            renderCardTitle={(row) => row.user.name}
            renderCardFields={(row) => [
              { label: 'Unidad', value: row.unit.full_identifier },
              { label: 'Relación', value: relationshipLabel(row.relationship) },
              { label: 'Estado', value: <ReadStatusPill read={row.read} /> },
              {
                label: 'Fecha',
                value: row.read_at ? displayFormatDate(row.read_at, 'medium') : '—',
              },
            ]}
          />
        </div>
      )}

      {pagination && <Pagination pagination={pagination} onPageChanged={handlePageChanged} />}
    </div>
  )
}
