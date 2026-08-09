import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { chargeStatusLabel, chargeStatusTone, type ICharge } from '@/modules/charge/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'
import { ChargeResource } from '@/modules/charge/services/ChargeResource'
import { useChargeStore } from '@/modules/charge/store/chargeStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import {
  DateRangeFilter,
  resolveDateRange,
  type DateRange,
} from '@/shared/components/filters/DateRangeFilter'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { RangeFilter } from '@/shared/components/filters/RangeFilter'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import type { Filter } from '@/shared/interfaces/list.types'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney, moneyToMinorUnits, pesosToMinorUnits } from '@/shared/utils/money'
import { useMutation } from '@tanstack/react-query'
import { BadgePercent, Ban, Download, Loader2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useShallow } from 'zustand/react/shallow'

const PERMISSIONS = {
  canCancel: 'cancel.charge',
  canDiscount: 'discount.charge',
  canUpdate: 'update.charge',
} as const

// ─── Filter state types ─────────────────────────────────────────────────────

/** All amount fields are stored in PESOS (display units) and converted to minor units on search. */
interface ChargeFilters {
  expectedAmountMinPesos: number | null
  expectedAmountMaxPesos: number | null
  paidAmountMinPesos: number | null
  paidAmountMaxPesos: number | null
  dueDate: DateRange
  unitIds: string[]
  ownerIds: string[]
  statusIds: string[]
  conceptIds: string[]
}

const EMPTY_DATE_RANGE: DateRange = { from: null, to: null, shortcut: null }

const INITIAL_FILTERS: ChargeFilters = {
  expectedAmountMinPesos: null,
  expectedAmountMaxPesos: null,
  paidAmountMinPesos: null,
  paidAmountMaxPesos: null,
  dueDate: EMPTY_DATE_RANGE,
  unitIds: [],
  ownerIds: [],
  statusIds: [],
  conceptIds: [],
}

/**
 * Tono de cargo → color hex del punto, en sincronía con StatusPill (DOT).
 * Permite que el dot del filtro "Estado" coincida con el pill de la tabla.
 */
const STATUS_TONE_COLOR: Record<PillTone, string> = {
  g: '#3F9A5E',
  y: '#C0862B',
  r: '#C0533C',
  b: '#3F6E8E',
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Builds Orion-compatible filter array from the local ChargeFilters state.
 * Amounts entered by the user are in pesos and converted to minor units.
 */
function buildChargeFilters(filters: ChargeFilters): Filter[] {
  const result: Filter[] = []

  if (filters.expectedAmountMinPesos !== null) {
    result.push({
      field: 'expected_amount',
      operator: '>=',
      value: pesosToMinorUnits(filters.expectedAmountMinPesos),
    })
  }
  if (filters.expectedAmountMaxPesos !== null) {
    result.push({
      field: 'expected_amount',
      operator: '<=',
      value: pesosToMinorUnits(filters.expectedAmountMaxPesos),
    })
  }
  if (filters.paidAmountMinPesos !== null) {
    result.push({
      field: 'paid_amount',
      operator: '>=',
      value: pesosToMinorUnits(filters.paidAmountMinPesos),
    })
  }
  if (filters.paidAmountMaxPesos !== null) {
    result.push({
      field: 'paid_amount',
      operator: '<=',
      value: pesosToMinorUnits(filters.paidAmountMaxPesos),
    })
  }

  const dueResolved = resolveDateRange(filters.dueDate)
  if (dueResolved.from) result.push({ field: 'due_date', operator: '>=', value: dueResolved.from })
  if (dueResolved.to) result.push({ field: 'due_date', operator: '<=', value: dueResolved.to })

  if (filters.unitIds.length > 0) {
    result.push({ field: 'unit_id', operator: 'in', value: filters.unitIds })
  }

  // Estado: charge.charge_status_id es FK directa (filterableBy del controller).
  if (filters.statusIds.length > 0) {
    result.push({ field: 'charge_status_id', operator: 'in', value: filters.statusIds })
  }

  // Concepto: charge.charge_concept_id es FK directa (filterableBy del controller),
  // no requiere el path anidado charge_batch.charge_concept_id.
  if (filters.conceptIds.length > 0) {
    result.push({ field: 'charge_concept_id', operator: 'in', value: filters.conceptIds })
  }

  return result
}

/**
 * Cargos individuales: consulta global con cancelación y descuento/condonación.
 * El alta de cargos se hace SIEMPRE vía cobros (charge_batch).
 */
export const ChargeListPage = () => {
  const permissions = useMultiplePermissions(PERMISSIONS)
  const [cancelTarget, setCancelTarget] = useState<ICharge | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [discountTarget, setDiscountTarget] = useState<ICharge | null>(null)
  const [discountPesos, setDiscountPesos] = useState('')
  const [discountReason, setDiscountReason] = useState('')

  // Filter state
  const [filters, setFilters] = useState<ChargeFilters>(INITIAL_FILTERS)

  // Catalogs for filter dropdowns — LAZY: cada filtro carga su catálogo recién
  // al abrirse, así entrar a la lista NO dispara ningún POST /selects.
  // UNIT y USER (los catálogos más pesados) van en hooks propios para que abrir
  // un filtro cargue SOLO su catálogo; estado y concepto (chicos, siempre
  // disponibles juntos) comparten un único hook.
  const {
    catalogs: unitCatalogs,
    enable: enableUnits,
    isLoading: loadingUnits,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.UNIT]: { _sort: 'number' },
      }),
      []
    )
  )
  const {
    catalogs: ownerCatalogs,
    enable: enableOwners,
    isLoading: loadingOwners,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.USER]: { _sort: 'name' },
      }),
      []
    )
  )
  const {
    catalogs: statusConceptCatalogs,
    enable: enableStatusConcept,
    isLoading: loadingStatusConcept,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.CHARGE_STATUS]: { _sort: 'name' },
        [CatalogType.CHARGE_CONCEPT]: { _sort: 'name' },
      }),
      []
    )
  )

  const unitOptions = useMemo<MultiSelectOption[]>(
    () =>
      (unitCatalogs[CatalogType.UNIT] ?? []).map((u) => ({
        value: u.id,
        label:
          (u.full_identifier as string | undefined) ?? (u.number as string | undefined) ?? u.id,
      })),
    [unitCatalogs]
  )

  const ownerOptions = useMemo<MultiSelectOption[]>(
    () =>
      (ownerCatalogs[CatalogType.USER] ?? []).map((u) => ({
        value: u.id,
        label: (u.name as string | undefined) ?? u.id,
      })),
    [ownerCatalogs]
  )

  const statusOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusConceptCatalogs[CatalogType.CHARGE_STATUS] ?? []).map((s) => ({
        value: s.id,
        label: (s.name as string | undefined) ?? s.id,
        color: STATUS_TONE_COLOR[chargeStatusTone(s.id)],
      })),
    [statusConceptCatalogs]
  )

  const conceptOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusConceptCatalogs[CatalogType.CHARGE_CONCEPT] ?? []).map((c) => ({
        value: c.id,
        label: (c.name as string | undefined) ?? c.id,
      })),
    [statusConceptCatalogs]
  )

  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<ICharge>({
      store: useChargeStore,
      resource: ChargeResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'due_date', direction: 'desc' }],
        includes: [{ relation: 'unit' }, { relation: 'concept' }, { relation: 'charge_status' }],
      },
    })
  const { entities, pagination, isLoading } = useChargeStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  const refresh = () => onSearch({ ...paramsSearch })

  const applyFilters = (next: ChargeFilters) => {
    setFilters(next)
    onSearch({
      ...paramsSearch,
      page: 1,
      filters: buildChargeFilters(next),
      // owner_ids is a top-level param (not an Orion filter field) handled by
      // ChargeController::applyOwnerFilter — persisted in paramsSearch so
      // subsequent pagination calls carry it through buildSearchExtras().
      owner_ids: next.ownerIds.length > 0 ? next.ownerIds : undefined,
    })
  }

  const resetFilters = () => {
    setFilters(INITIAL_FILTERS)
    onReset()
  }

  const cancelMutation = useMutation({
    mutationFn: ({ charge, reason }: { charge: ICharge; reason: string }) =>
      ChargeResource.cancel(charge.id, reason),
    onSuccess: () => {
      toast.success('Cargo cancelado')
      setCancelTarget(null)
      setCancelReason('')
      refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo cancelar el cargo'
      ),
  })

  const discountMutation = useMutation({
    mutationFn: ({ charge, amount, reason }: { charge: ICharge; amount: number; reason: string }) =>
      ChargeResource.discount(charge.id, { amount, reason }),
    onSuccess: () => {
      toast.success('Descuento aplicado')
      setDiscountTarget(null)
      setDiscountPesos('')
      setDiscountReason('')
      refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo aplicar el descuento'
      ),
  })

  const canAct = permissions.canCancel || permissions.canDiscount || permissions.canUpdate

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Cargos'
        description='Cargos individuales por unidad — se generan desde Cobros'
        actions={
          <PermissionGuard permission='report.charge'>
            <Button
              variant='outline'
              onClick={() => onReport('Charge', 'xlsx', 'cargos')}
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
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={(params) => onSearch(params)}
          onReset={(_data) => resetFilters()}
          placeholder='Buscar por concepto…'
          defaultFiltersExpanded={false}
          toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
        >
          <div className='flex flex-wrap gap-2'>
            <RangeFilter
              label='Monto esperado ($)'
              min={filters.expectedAmountMinPesos}
              max={filters.expectedAmountMaxPesos}
              onChange={({ min, max }) =>
                applyFilters({
                  ...filters,
                  expectedAmountMinPesos: min,
                  expectedAmountMaxPesos: max,
                })
              }
              placeholder={{ min: 'Mín $', max: 'Máx $' }}
              step={0.01}
            />
            <RangeFilter
              label='Monto pagado ($)'
              min={filters.paidAmountMinPesos}
              max={filters.paidAmountMaxPesos}
              onChange={({ min, max }) =>
                applyFilters({ ...filters, paidAmountMinPesos: min, paidAmountMaxPesos: max })
              }
              placeholder={{ min: 'Mín $', max: 'Máx $' }}
              step={0.01}
            />
            <DateRangeFilter
              label='Fecha de vencimiento'
              value={filters.dueDate}
              onChange={(dueDate) => applyFilters({ ...filters, dueDate })}
              shortcuts={['overdue', 'this_month']}
            />
            {/* Cada filtro carga su catálogo al abrir el popover por primera vez
                (onFirstOpen → enable) — así la lista no paga /selects al montar. */}
            <MultiSelectFilter
              label='Unidad'
              options={unitOptions}
              value={filters.unitIds}
              onChange={(unitIds) => applyFilters({ ...filters, unitIds })}
              placeholder='Todas las unidades'
              compact
              onFirstOpen={enableUnits}
              isLoading={loadingUnits}
            />
            <MultiSelectFilter
              label='Propietario de cobro'
              options={ownerOptions}
              value={filters.ownerIds}
              onChange={(ownerIds) => applyFilters({ ...filters, ownerIds })}
              placeholder='Todos los propietarios'
              compact
              onFirstOpen={enableOwners}
              isLoading={loadingOwners}
            />
            <MultiSelectFilter
              label='Concepto'
              options={conceptOptions}
              value={filters.conceptIds}
              onChange={(conceptIds) => applyFilters({ ...filters, conceptIds })}
              placeholder='Todos los conceptos'
              compact
              onFirstOpen={enableStatusConcept}
              isLoading={loadingStatusConcept}
            />
            <MultiSelectFilter
              label='Estado'
              options={statusOptions}
              value={filters.statusIds}
              onChange={(statusIds) => applyFilters({ ...filters, statusIds })}
              placeholder='Todos los estados'
              compact
              onFirstOpen={enableStatusConcept}
              isLoading={loadingStatusConcept}
            />
          </div>
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<ICharge>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Unidad</TableHead>
                    <TableHead scope='col'>Concepto</TableHead>
                    <TableHead scope='col'>Vence</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Esperado
                    </TableHead>
                    <TableHead scope='col' className='text-right'>
                      Pagado
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {canAct && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(charge) => (
                <TableRow key={charge.id} className={charge.is_cancelled ? 'opacity-50' : ''}>
                  <TableCell className='font-medium'>
                    {charge.unit?.full_identifier ?? charge.unit?.number ?? '—'}
                  </TableCell>
                  <TableCell>{charge.concept?.name ?? '—'}</TableCell>
                  <TableCell>
                    {charge.due_date ? displayFormatDate(charge.due_date, 'mediumDate') : '—'}
                  </TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {displayMoney(charge.expected_amount)}
                    {moneyToMinorUnits(charge.discount_amount ?? 0) > 0 && (
                      <span className='block text-[11px] text-[var(--green-deep)]'>
                        −{displayMoney(charge.discount_amount ?? 0)} desc.
                      </span>
                    )}
                  </TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {displayMoney(charge.paid_amount)}
                  </TableCell>
                  <TableCell>
                    {charge.is_cancelled ? (
                      <StatusPill tone='r'>Cancelado</StatusPill>
                    ) : (
                      <StatusPill tone={chargeStatusTone(charge.charge_status_id)}>
                        {chargeStatusLabel(charge)}
                      </StatusPill>
                    )}
                  </TableCell>
                  {canAct && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      {!charge.is_cancelled && (
                        <div className='flex justify-end gap-1'>
                          {permissions.canDiscount && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8'
                              onClick={() => setDiscountTarget(charge)}
                              aria-label={`Aplicar descuento al cargo de ${charge.unit?.full_identifier ?? charge.unit?.number ?? 'la unidad'}`}
                              title='Descuento / condonación'
                            >
                              <BadgePercent className='size-4' />
                            </Button>
                          )}
                          {permissions.canCancel && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8 text-destructive hover:text-destructive'
                              onClick={() => setCancelTarget(charge)}
                              aria-label={`Cancelar cargo de ${charge.unit?.full_identifier ?? charge.unit?.number ?? 'la unidad'}`}
                              title='Cancelar cargo'
                            >
                              <Ban className='size-4' />
                            </Button>
                          )}
                        </div>
                      )}
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(charge) =>
                charge.unit?.full_identifier ?? charge.unit?.number ?? '—'
              }
              renderCardFields={(charge) => [
                { label: 'Concepto', value: charge.concept?.name ?? '—' },
                {
                  label: 'Vence',
                  value: charge.due_date ? displayFormatDate(charge.due_date, 'mediumDate') : '—',
                },
                {
                  label: 'Esperado',
                  value: (
                    <span className='tabular-nums'>
                      {displayMoney(charge.expected_amount)}
                      {moneyToMinorUnits(charge.discount_amount ?? 0) > 0 && (
                        <span className='block text-[11px] text-[var(--green-deep)]'>
                          −{displayMoney(charge.discount_amount ?? 0)} desc.
                        </span>
                      )}
                    </span>
                  ),
                },
                {
                  label: 'Pagado',
                  value: <span className='tabular-nums'>{displayMoney(charge.paid_amount)}</span>,
                },
                {
                  label: 'Estado',
                  value: charge.is_cancelled ? (
                    <StatusPill tone='r'>Cancelado</StatusPill>
                  ) : (
                    <StatusPill tone={chargeStatusTone(charge.charge_status_id)}>
                      {chargeStatusLabel(charge)}
                    </StatusPill>
                  ),
                },
              ]}
              renderActions={
                canAct
                  ? (charge) =>
                      !charge.is_cancelled ? (
                        <div className='flex justify-end gap-1'>
                          {permissions.canDiscount && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8'
                              onClick={() => setDiscountTarget(charge)}
                              aria-label={`Aplicar descuento al cargo de ${charge.unit?.full_identifier ?? charge.unit?.number ?? 'la unidad'}`}
                              title='Descuento / condonación'
                            >
                              <BadgePercent className='size-4' />
                            </Button>
                          )}
                          {permissions.canCancel && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8 text-destructive hover:text-destructive'
                              onClick={() => setCancelTarget(charge)}
                              aria-label={`Cancelar cargo de ${charge.unit?.full_identifier ?? charge.unit?.number ?? 'la unidad'}`}
                              title='Cancelar cargo'
                            >
                              <Ban className='size-4' />
                            </Button>
                          )}
                        </div>
                      ) : null
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      {/* cancelar cargo */}
      <Dialog open={Boolean(cancelTarget)} onOpenChange={(open) => !open && setCancelTarget(null)}>
        <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
          <DialogHeader className='px-6 pt-6'>
            <DialogTitle>Cancelar cargo</DialogTitle>
          </DialogHeader>
          <div className='px-6 py-2'>
            <p className='mb-3 text-[13px] text-[var(--ink-2)]'>
              El cargo de {displayMoney(cancelTarget?.expected_amount ?? 0)} para{' '}
              <b className='font-semibold'>
                {cancelTarget?.unit?.full_identifier ?? cancelTarget?.unit?.number}
              </b>{' '}
              quedará cancelado y fuera del adeudo.
            </p>
            <Textarea
              rows={3}
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder='Motivo de la cancelación (obligatorio)…'
              className='resize-none'
            />
          </div>
          <DialogFooter className='px-6 pb-6'>
            <Button
              variant='outline'
              onClick={() => setCancelTarget(null)}
              disabled={cancelMutation.isPending}
            >
              Volver
            </Button>
            <Button
              variant='destructive'
              disabled={cancelMutation.isPending || cancelReason.trim().length === 0}
              onClick={() =>
                cancelTarget &&
                cancelMutation.mutate({ charge: cancelTarget, reason: cancelReason.trim() })
              }
            >
              {cancelMutation.isPending ? 'Cancelando…' : 'Cancelar cargo'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* descuento */}
      <Dialog
        open={Boolean(discountTarget)}
        onOpenChange={(open) => !open && setDiscountTarget(null)}
      >
        <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
          <DialogHeader className='px-6 pt-6'>
            <DialogTitle>Descuento / condonación</DialogTitle>
          </DialogHeader>
          <div className='px-6 py-2'>
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor='discount-amount' required>
                  Monto a descontar
                </FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Input
                    id='discount-amount'
                    type='number'
                    step='0.01'
                    min={0}
                    className='pl-7'
                    value={discountPesos}
                    onChange={(e) => setDiscountPesos(e.target.value)}
                    placeholder='0.00'
                  />
                </div>
                <FieldError>
                  {Number(discountPesos) <= 0 && discountPesos !== ''
                    ? 'Debe ser mayor a 0'
                    : undefined}
                </FieldError>
              </Field>
              <Field>
                <FieldLabel htmlFor='discount-reason' required>
                  Motivo
                </FieldLabel>
                <Textarea
                  id='discount-reason'
                  rows={2}
                  className='resize-none'
                  value={discountReason}
                  onChange={(e) => setDiscountReason(e.target.value)}
                  placeholder='Acuerdo de asamblea, convenio, etc.'
                />
              </Field>
            </FieldGroup>
          </div>
          <DialogFooter className='px-6 pb-6'>
            <Button
              variant='outline'
              onClick={() => setDiscountTarget(null)}
              disabled={discountMutation.isPending}
            >
              Cancelar
            </Button>
            <Button
              disabled={
                discountMutation.isPending ||
                Number(discountPesos) <= 0 ||
                discountReason.trim().length === 0
              }
              onClick={() =>
                discountTarget &&
                discountMutation.mutate({
                  charge: discountTarget,
                  amount: pesosToMinorUnits(Number(discountPesos)),
                  reason: discountReason.trim(),
                })
              }
            >
              {discountMutation.isPending ? 'Aplicando…' : 'Aplicar descuento'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
