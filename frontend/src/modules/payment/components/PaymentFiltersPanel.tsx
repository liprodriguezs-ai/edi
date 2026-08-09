import { PAYMENT_STATUS } from '@/modules/payment/schema'
import type { PaymentFilters } from '@/modules/payment/paymentFilters'
import type { PillTone } from '@/shared/components/atoms/StatusPill'
import { DateRangeFilter } from '@/shared/components/filters/DateRangeFilter'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { RangeFilter } from '@/shared/components/filters/RangeFilter'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useMemo } from 'react'

/**
 * Tono de estado de pago → color hex del punto, en sincronía con StatusPill (DOT).
 * Permite que el dot del filtro "Estado" coincida con el pill de la tabla.
 */
const STATUS_TONE_COLOR: Record<PillTone, string> = {
  g: '#3F9A5E',
  y: '#C0862B',
  r: '#C0533C',
  b: '#3F6E8E',
}

function statusToneColor(statusId: string): string {
  switch (statusId) {
    case PAYMENT_STATUS.CONFIRMED:
      return STATUS_TONE_COLOR.g
    case PAYMENT_STATUS.PENDING_VALIDATION:
      return STATUS_TONE_COLOR.y
    default:
      return STATUS_TONE_COLOR.r
  }
}

interface PaymentFiltersPanelProps {
  filters: PaymentFilters
  onChange: (next: PaymentFilters) => void
}

/**
 * Panel de filtros de la lista de Pagos. Carga sus propios catálogos y emite el
 * estado completo de PaymentFilters al padre vía onChange (AND semantics).
 */
export function PaymentFiltersPanel({ filters, onChange }: PaymentFiltersPanelProps) {
  // MAXIMUM-SAVINGS: cada filtro tiene su PROPIO catálogo lazy, así abrir un
  // dropdown dispara solo SU `/selects` (UNIT es pesado/independiente; los
  // estados/métodos/cuentas son chicos pero independientes entre sí). Ninguno
  // se carga al montar la lista — recién al interactuar con el filtro.
  // Los cuatro filtros son `compact`: un valor pre-aplicado se muestra como
  // conteo ("N seleccionados"), nunca necesita el catálogo para pintar labels,
  // así que NO hace falta eager-load para deep-links.
  const statusCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.PAYMENT_STATUS]: { _sort: 'name' } }), [])
  )
  const methodCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.PAYMENT_METHOD]: { _sort: 'order' } }), [])
  )
  const unitCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.UNIT]: { _sort: 'number' } }), [])
  )
  const bankAccountCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.BANK_ACCOUNT]: { _sort: 'name' } }), [])
  )

  const statusOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusCatalog.catalogs[CatalogType.PAYMENT_STATUS] ?? []).map((s) => ({
        value: s.id,
        label: (s.name as string | undefined) ?? s.id,
        color: statusToneColor(s.id),
      })),
    [statusCatalog.catalogs]
  )

  const methodOptions = useMemo<MultiSelectOption[]>(
    () =>
      (methodCatalog.catalogs[CatalogType.PAYMENT_METHOD] ?? []).map((m) => ({
        value: m.id,
        label: (m.name as string | undefined) ?? m.id,
      })),
    [methodCatalog.catalogs]
  )

  const unitOptions = useMemo<MultiSelectOption[]>(
    () =>
      (unitCatalog.catalogs[CatalogType.UNIT] ?? []).map((u) => ({
        value: u.id,
        label:
          (u.full_identifier as string | undefined) ?? (u.number as string | undefined) ?? u.id,
      })),
    [unitCatalog.catalogs]
  )

  const bankAccountOptions = useMemo<MultiSelectOption[]>(
    () =>
      (bankAccountCatalog.catalogs[CatalogType.BANK_ACCOUNT] ?? []).map((b) => ({
        value: b.id,
        label: (b.name as string | undefined) ?? b.id,
      })),
    [bankAccountCatalog.catalogs]
  )

  return (
    <div className='flex flex-wrap gap-2'>
      <RangeFilter
        label='Monto ($)'
        min={filters.totalAmountMinPesos}
        max={filters.totalAmountMaxPesos}
        onChange={({ min, max }) =>
          onChange({ ...filters, totalAmountMinPesos: min, totalAmountMaxPesos: max })
        }
        placeholder={{ min: 'Mín $', max: 'Máx $' }}
        step={0.01}
      />
      <DateRangeFilter
        label='Fecha de pago'
        value={filters.paidAt}
        onChange={(paidAt) => onChange({ ...filters, paidAt })}
        shortcuts={['today', 'this_week', 'this_month']}
      />
      {/* Cada filtro carga su catálogo lazy al abrir el popover por primera vez
          (onFirstOpen → enable, idempotente); la lista no paga /selects al montar. */}
      <MultiSelectFilter
        label='Estado'
        options={statusOptions}
        value={filters.statusIds}
        onChange={(statusIds) => onChange({ ...filters, statusIds })}
        placeholder='Todos los estados'
        compact
        onFirstOpen={statusCatalog.enable}
        isLoading={statusCatalog.isLoading}
      />
      <MultiSelectFilter
        label='Método de pago'
        options={methodOptions}
        value={filters.methodIds}
        onChange={(methodIds) => onChange({ ...filters, methodIds })}
        placeholder='Todos los métodos'
        compact
        onFirstOpen={methodCatalog.enable}
        isLoading={methodCatalog.isLoading}
      />
      <MultiSelectFilter
        label='Unidad'
        options={unitOptions}
        value={filters.unitIds}
        onChange={(unitIds) => onChange({ ...filters, unitIds })}
        placeholder='Todas las unidades'
        compact
        onFirstOpen={unitCatalog.enable}
        isLoading={unitCatalog.isLoading}
      />
      <MultiSelectFilter
        label='Cuenta destino'
        options={bankAccountOptions}
        value={filters.bankAccountIds}
        onChange={(bankAccountIds) => onChange({ ...filters, bankAccountIds })}
        placeholder='Todas las cuentas'
        compact
        onFirstOpen={bankAccountCatalog.enable}
        isLoading={bankAccountCatalog.isLoading}
      />
    </div>
  )
}
