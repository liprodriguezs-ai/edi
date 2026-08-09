import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { RangeFilter } from '@/shared/components/filters/RangeFilter'
import type { DelinquencyQueryParams, DelinquencyUnit } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import {
  displayMoney,
  formatMinorUnits,
  moneyToMinorUnits,
  pesosToMinorUnits,
} from '@/shared/utils/money'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type { IEntity } from '@/shared/interfaces/Entity'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download, Loader2, Search } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

const KPI = 'rounded-[14px] border border-border bg-card p-4 shadow-sm'
const KPI_LABEL =
  'flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'
const KPI_VALUE = 'mt-1 text-[24px] font-semibold tracking-[-0.01em] tabular-nums'

/** Meses transcurridos (aprox.) desde una fecha */
function monthsSince(date: string | null | undefined): number {
  if (!date) return 0
  const ms = Date.now() - new Date(date).getTime()
  return Math.max(0, ms / (1000 * 60 * 60 * 24 * 30.44))
}

/** Opciones del filtro de antigüedad ("meses de deuda"): mínimo de meses vencidos. */
const MONTHS_OPTIONS = [
  { value: '1', label: '1+ meses' },
  { value: '2', label: '2+ meses' },
  { value: '3', label: '3+ meses' },
  { value: '6', label: '6+ meses' },
  { value: '12', label: '12+ meses' },
] as const

/** Sentinela para "sin filtro" — shadcn Select no admite value=''. */
const ALL_MONTHS = 'all'

/**
 * Fila de tabla: DelinquencyUnit + IEntity (la constraint de ResponsiveDataTable,
 * que incluye un index signature). El `id` requerido se MATERIALIZA en el map de
 * `units` — sin casts fabricados sobre datos que no lo traen.
 */
type DelinquencyRow = DelinquencyUnit & IEntity

/**
 * Dashboard de morosidad (§8.6.3, pantalla "morosidad" del mock v3):
 * KPIs de adeudo + tabla de unidades con saldo, ordenada por monto.
 *
 * Los filtros son SERVER-SIDE (GET /delinquency los aplica sobre la agregación
 * compartida con el export): la búsqueda combina unidad y residente, y los KPIs
 * reflejan el conjunto filtrado ("cuánto se debe de lo que estoy viendo").
 */
export const DelinquencyPage = () => {
  // Búsqueda combinada: unidad (número, tipo, torre) O nombre de residente activo.
  const [search, setSearch] = useState('')
  const [conceptIds, setConceptIds] = useState<string[]>([])
  const [minMonths, setMinMonths] = useState<string>(ALL_MONTHS)
  // Montos capturados en PESOS; se convierten a minor units al armar los params.
  const [minPesos, setMinPesos] = useState<number | null>(null)
  const [maxPesos, setMaxPesos] = useState<number | null>(null)
  // Busy state LOCAL al botón de exportar (regla de proyecto: nunca reusar un
  // isLoading global para el spinner de descarga).
  const [isExporting, setIsExporting] = useState(false)

  // Solo los campos TECLEADOS (texto y montos) se debouncean; concepto y meses
  // son clicks discretos y aplican de inmediato.
  const typedFilters = useMemo(
    () => ({ search: search.trim(), minPesos, maxPesos }),
    [search, minPesos, maxPesos]
  )
  const [debouncedTyped, setDebouncedTyped] = useState(typedFilters)
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedTyped(typedFilters), 350)
    return () => clearTimeout(timer)
  }, [typedFilters])

  const params = useMemo<DelinquencyQueryParams>(() => {
    const next: DelinquencyQueryParams = {}
    if (debouncedTyped.search) next.search = debouncedTyped.search
    // Orden estable para que [A,B] y [B,A] compartan queryKey (y caché).
    if (conceptIds.length > 0) next.charge_concept_ids = [...conceptIds].sort()
    if (minMonths !== ALL_MONTHS) next.min_months_overdue = Number(minMonths)
    // Clamp a >= 0: un monto negativo tecleado no debe producir un 422 silencioso.
    if (debouncedTyped.minPesos !== null) {
      next.min_amount = pesosToMinorUnits(Math.max(0, debouncedTyped.minPesos))
    }
    if (debouncedTyped.maxPesos !== null) {
      next.max_amount = pesosToMinorUnits(Math.max(0, debouncedTyped.maxPesos))
    }
    return next
  }, [debouncedTyped, conceptIds, minMonths])

  const hasActiveFilters = Object.keys(params).length > 0

  const { data, isLoading, isError, isFetching, isPlaceholderData, refetch } = useQuery({
    queryKey: ['delinquency', params],
    queryFn: () => PaymentResource.delinquency(params),
    // Mantiene la tabla anterior visible mientras llega el refetch filtrado.
    placeholderData: keepPreviousData,
  })

  // Señal visual de refetch: con keepPreviousData la tabla muestra el filtro
  // ANTERIOR mientras carga — se atenúa para que no parezca el resultado final.
  const refreshingClass =
    isFetching || isPlaceholderData ? 'opacity-60 transition-opacity' : 'transition-opacity'

  // Catálogo de conceptos LAZY: se carga la PRIMERA vez que se abre el dropdown
  // (onFirstOpen → enable) — entrar a la página no dispara POST /selects.
  const {
    catalogs: conceptCatalogs,
    enable: enableConcepts,
    isLoading: loadingConcepts,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.CHARGE_CONCEPT]: { _sort: 'name' },
      }),
      []
    )
  )

  const conceptOptions = useMemo<MultiSelectOption[]>(
    () =>
      (conceptCatalogs[CatalogType.CHARGE_CONCEPT] ?? []).map((concept) => ({
        value: concept.id,
        label: concept.name,
      })),
    [conceptCatalogs]
  )

  /**
   * Descarga el xlsx de morosidad respetando los filtros ACTIVOS: el archivo
   * contiene exactamente lo que muestra la tabla (el backend comparte la
   * agregación y los filtros entre GET /delinquency y su export).
   */
  const handleExport = useCallback(async () => {
    setIsExporting(true)
    try {
      const blob = await PaymentResource.delinquencyReport(params)
      if (blob.size <= 0) {
        toast.info('No hay datos para exportar')
        return
      }
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = 'morosidad.xlsx'
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
      toast.success('Reporte descargado correctamente')
    } catch {
      toast.error('Error al descargar el reporte, inténtalo más tarde')
    } finally {
      setIsExporting(false)
    }
  }, [params])

  // Materializa el `id` requerido por la tabla; una unidad soft-deleted llega
  // con unit_id null y recibe una key sintética estable dentro del render.
  const units = useMemo<DelinquencyRow[]>(
    () =>
      (data?.data ?? []).map((unit, index) => ({
        ...unit,
        id: unit.unit_id ?? `deleted-unit-${index}`,
      })),
    [data]
  )

  const stats = useMemo(() => {
    const all = data?.data ?? []
    if (all.length === 0) return null
    const total = moneyToMinorUnits(data?.total_outstanding)
    const worst = all[0]
    const avgAge =
      all.reduce((sum, unit) => sum + monthsSince(unit.oldest_due_date), 0) / all.length
    return {
      total,
      count: data?.units_with_debt ?? all.length,
      average: Math.round(total / all.length),
      worst,
      avgAge,
    }
  }, [data])

  return (
    <div className='space-y-5'>
      <div className='flex flex-wrap items-end justify-between gap-4'>
        <div>
          <div className='mb-[7px] text-[10.5px] font-semibold uppercase tracking-[0.15em] text-[var(--ink-4)]'>
            Cobranza
          </div>
          <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
            Control de <b className='font-semibold text-[var(--green)]'>morosidad</b>
          </h1>
          {/* aria-live: anuncia el nuevo conteo cuando cambian los filtros */}
          <div aria-live='polite' className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
            {stats
              ? `${stats.count} unidades con adeudo vencido · actualizado hoy`
              : 'Unidades con adeudo vencido'}
          </div>
        </div>
        <PermissionGuard permission='report.payment'>
          <Button
            variant='outline'
            onClick={() => void handleExport()}
            disabled={isExporting}
            className='flex items-center gap-2'
          >
            {isExporting ? (
              <Loader2 className='h-4 w-4 animate-spin' />
            ) : (
              <Download className='h-4 w-4' />
            )}
            Descargar
          </Button>
        </PermissionGuard>
      </div>

      {isError ? (
        <div className='rounded-[14px] border border-border bg-card px-6 py-12 text-center'>
          <p className='text-sm text-[var(--ink-3)]'>
            No se pudo cargar la morosidad. Revisa tu conexión e inténtalo de nuevo.
          </p>
          <Button variant='outline' className='mt-4' onClick={() => void refetch()}>
            Reintentar
          </Button>
        </div>
      ) : isLoading && !data ? (
        <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando morosidad…</p>
      ) : (
        <>
          {/* KPIs — reflejan el conjunto FILTRADO */}
          {stats && (
            <div
              className={`grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4 ${refreshingClass}`}
            >
              <div className={KPI}>
                <div className={KPI_LABEL}>
                  <span className='size-2.5 rounded-[4px] bg-[#C0533C]' aria-hidden />
                  Adeudo vencido
                </div>
                <div className={`${KPI_VALUE} text-[#C0533C]`}>{formatMinorUnits(stats.total)}</div>
                <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
                  {stats.count} unidades morosas
                </div>
              </div>
              <div className={KPI}>
                <div className={KPI_LABEL}>
                  <span className='size-2.5 rounded-[4px] bg-[var(--ink-3)]' aria-hidden />
                  Adeudo promedio
                </div>
                <div className={KPI_VALUE}>{formatMinorUnits(stats.average)}</div>
                <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>por unidad morosa</div>
              </div>
              <div className={KPI}>
                <div className={KPI_LABEL}>
                  <span className='size-2.5 rounded-[4px] bg-[#C0533C]' aria-hidden />
                  Más morosa
                </div>
                <div className={KPI_VALUE}>
                  {stats.worst.unit_full_identifier ?? stats.worst.unit_number ?? '—'}
                </div>
                <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
                  {displayMoney(stats.worst.outstanding)}
                  {stats.worst.oldest_due_date
                    ? ` · desde ${displayFormatDate(stats.worst.oldest_due_date, 'mediumDate')}`
                    : ''}
                </div>
              </div>
              <div className={KPI}>
                <div className={KPI_LABEL}>
                  <span className='size-2.5 rounded-[4px] bg-[#B7923B]' aria-hidden />
                  Antigüedad prom.
                </div>
                <div className={KPI_VALUE}>
                  {stats.avgAge.toFixed(1)}{' '}
                  <small className='text-sm font-medium text-[var(--ink-3)]'>meses</small>
                </div>
                <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>del cargo más antiguo</div>
              </div>
            </div>
          )}

          {/* filtros (server-side) */}
          <div className='flex flex-wrap gap-2'>
            <div className='flex w-full flex-col gap-1 sm:w-72'>
              <label
                htmlFor='delinquency-search'
                className='text-xs font-medium text-muted-foreground'
              >
                Unidad o residente
              </label>
              <div className='relative'>
                <Search
                  className='pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-[var(--ink-4)]'
                  aria-hidden
                />
                <Input
                  id='delinquency-search'
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder='Buscar unidad o residente…'
                  maxLength={255}
                  className='h-9 bg-card pl-8 text-sm'
                />
              </div>
            </div>
            <MultiSelectFilter
              label='Concepto'
              options={conceptOptions}
              value={conceptIds}
              onChange={setConceptIds}
              placeholder='Todos los conceptos'
              compact
              onFirstOpen={enableConcepts}
              isLoading={loadingConcepts}
            />
            <div className='flex w-full flex-col gap-1 sm:w-48'>
              <label
                htmlFor='delinquency-months'
                className='text-xs font-medium text-muted-foreground'
              >
                Meses de deuda
              </label>
              <Select value={minMonths} onValueChange={setMinMonths}>
                <SelectTrigger
                  id='delinquency-months'
                  aria-label='Meses de deuda'
                  className='h-9 w-full bg-card text-sm'
                >
                  <SelectValue placeholder='Cualquier antigüedad' />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL_MONTHS}>Cualquier antigüedad</SelectItem>
                  {MONTHS_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <RangeFilter
              label='Adeudo ($)'
              min={minPesos}
              max={maxPesos}
              onChange={({ min, max }) => {
                setMinPesos(min)
                setMaxPesos(max)
              }}
              placeholder={{ min: 'Mín $', max: 'Máx $' }}
              step={0.01}
            />
          </div>

          {units.length === 0 ? (
            <p className='rounded-[14px] border border-border bg-card px-6 py-12 text-center text-sm text-[var(--ink-3)]'>
              {hasActiveFilters
                ? 'Ninguna unidad coincide con los filtros aplicados.'
                : '🎉 Ninguna unidad tiene saldo vencido. El condominio está al corriente.'}
            </p>
          ) : (
            <div className={refreshingClass}>
              <ResponsiveDataTable<DelinquencyRow>
                rows={units}
                getRowKey={(unit) => unit.id}
                header={
                  <TableHeader>
                    <TableRow>
                      <TableHead scope='col'>Unidad</TableHead>
                      <TableHead scope='col'>Cargos vencidos</TableHead>
                      <TableHead scope='col'>Adeudo más antiguo</TableHead>
                      <TableHead scope='col' className='text-right'>
                        Adeudo vencido
                      </TableHead>
                      <TableHead scope='col' className='text-right' />
                    </TableRow>
                  </TableHeader>
                }
                renderRow={(unit) => (
                  <TableRow key={unit.id}>
                    <TableCell className='font-medium'>
                      {unit.unit_full_identifier ?? unit.unit_number ?? '—'}
                    </TableCell>
                    <TableCell className='text-[var(--ink-2)]'>{unit.charge_count}</TableCell>
                    <TableCell className='text-[var(--ink-2)]'>
                      {unit.oldest_due_date
                        ? displayFormatDate(unit.oldest_due_date, 'mediumDate')
                        : '—'}
                    </TableCell>
                    <TableCell className='text-right font-semibold tabular-nums text-[#C0533C]'>
                      {displayMoney(unit.outstanding)}
                    </TableCell>
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      {unit.unit_id && (
                        <Link
                          to={`/statement?unit=${unit.unit_id}`}
                          className='text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
                        >
                          Estado de cuenta →
                        </Link>
                      )}
                    </TableCell>
                  </TableRow>
                )}
                renderCardTitle={(unit) => unit.unit_full_identifier ?? unit.unit_number ?? '—'}
                renderCardFields={(unit) => [
                  { label: 'Cargos vencidos', value: unit.charge_count },
                  {
                    label: 'Adeudo más antiguo',
                    value: unit.oldest_due_date
                      ? displayFormatDate(unit.oldest_due_date, 'mediumDate')
                      : '—',
                  },
                  {
                    label: 'Adeudo vencido',
                    value: (
                      <span className='font-semibold tabular-nums text-[#C0533C]'>
                        {displayMoney(unit.outstanding)}
                      </span>
                    ),
                  },
                ]}
                renderActions={(unit) =>
                  unit.unit_id ? (
                    <Link
                      to={`/statement?unit=${unit.unit_id}`}
                      className='text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
                    >
                      Estado de cuenta →
                    </Link>
                  ) : null
                }
              />
            </div>
          )}
        </>
      )}
    </div>
  )
}
