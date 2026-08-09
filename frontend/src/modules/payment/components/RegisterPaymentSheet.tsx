import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Textarea } from '@/components/ui/textarea'
import type { ICharge } from '@/modules/charge/schema'
import { ChargeResource } from '@/modules/charge/services/ChargeResource'
import {
  PAYMENT_METHOD,
  registerPaymentSchema,
  type IPayment,
  type RegisterPaymentValues,
  type StatementResponse,
} from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { DirectChargeFields } from '@/modules/payment/components/DirectChargeFields'
import { UnitDebtSummary } from '@/modules/payment/components/UnitDebtSummary'
import { UnitCombobox } from '@/modules/unit/components/UnitCombobox'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { todayLocalDateString } from '@/shared/utils/dateHelper'
import {
  displayMoney,
  formatMinorUnits,
  moneyToMinorUnits,
  pesosToMinorUnits,
} from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Controller,
  useForm,
  type Control,
  type FieldErrors,
  type UseFormRegister,
  type UseFormSetValue,
} from 'react-hook-form'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface RegisterPaymentSheetProps {
  isOpen: boolean
  onClose: () => void
  presetUnitId?: string
  presetUnitLabel?: string
  /**
   * Habilita el modo "Cobro directo (servicio eventual)" dentro del sheet.
   * Solo la pantalla de Pagos lo pasa; el resto de consumidores conserva el
   * flujo clásico sobre cargos pendientes. Además del prop, el modo exige los
   * permisos create.charge Y create.payment (se verifican dentro del sheet).
   */
  allowDirectCharge?: boolean
  /** Se invoca tras registrar el pago con éxito, para que el padre recargue su listado (store Zustand). */
  onRegistered?: () => void
}

interface ChargeRowInfo {
  charge: ICharge
  outstanding: number
}

interface FifoRow extends ChargeRowInfo {
  applied: number
}

interface FifoPreview {
  rows: FifoRow[]
  surplus: number
}

interface CatalogItem {
  id: string
  name?: string
}

interface UsePaymentFormReturn {
  register: UseFormRegister<RegisterPaymentValues>
  control: Control<RegisterPaymentValues>
  errors: FieldErrors<RegisterPaymentValues>
  setValue: UseFormSetValue<RegisterPaymentValues>
  unitId: string
  totalMinor: number
  isCash: boolean
  applicationMode: string
  chargeType: 'pending' | 'direct'
  conceptMode: 'catalog' | 'other'
  chargeConceptId: string | undefined
  receipt: File | null
  setReceipt: (f: File | null) => void
  receiptError: string | null
  setReceiptError: (message: string | null) => void
  manualAmounts: Record<string, number>
  manualTotal: number
  pendingCharges: ChargeRowInfo[]
  fifoPreview: FifoPreview
  statement: StatementResponse | undefined
  /** Deuda total de la unidad (Σ saldos pendientes) en minor units. */
  outstandingMinor: number
  handleToggleCharge: (id: string, checked: boolean, outstanding: number) => void
  handleAmountChange: (id: string, pesos: number, outstanding: number) => void
  isPending: boolean
  submit: (e?: React.BaseSyntheticEvent) => Promise<void>
}

// ---------------------------------------------------------------------------
// Module-level helpers
// ---------------------------------------------------------------------------

const CATALOG_REQUEST = {
  [CatalogType.PAYMENT_METHOD]: { _sort: 'order' },
  [CatalogType.BANK_ACCOUNT]: { _sort: 'name' },
} as const

/** Extensiones aceptadas por POST /charge/direct (mimes:pdf,jpg,jpeg,png,webp). HEIC/HEIF quedan fuera a propósito. */
const DIRECT_RECEIPT_ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp'

/** Tope de tamaño del comprobante en cobro directo — igual al `max:10240` (KB) del backend. */
const MAX_RECEIPT_SIZE_BYTES = 10 * 1024 * 1024

/** Saldo efectivo del cargo: esperado − descuento − pagado */
function chargeOutstanding(charge: ICharge): number {
  const expected = moneyToMinorUnits(charge.expected_amount)
  const discount = moneyToMinorUnits(charge.discount_amount ?? 0)
  const paid = moneyToMinorUnits(charge.paid_amount)
  return Math.max(0, expected - discount - paid)
}

function buildDefaultValues(presetUnitId: string | undefined): RegisterPaymentValues {
  return {
    unit_id: presetUnitId ?? '',
    payer_user_id: undefined,
    paid_at: todayLocalDateString(),
    total_amount: 0,
    payment_method_id: PAYMENT_METHOD.CASH,
    bank_account_id: '',
    reference: '',
    physical_receipt_number: '',
    notes: '',
    application_mode: 'auto',
    charge_type: 'pending',
    concept_mode: 'catalog',
    charge_concept_id: '',
    concept_free_text: '',
  }
}

function buildFifoPreview(
  charges: ChargeRowInfo[],
  totalMinor: number,
  creditMinor: number
): FifoPreview {
  // The backend (AUTO mode) consumes the unit's existing credit balance FIRST, then the
  // payment amount, against the oldest charges. Seed the preview with the same combined
  // pool so the per-charge "applied" amounts and the surplus match what the backend does
  // — otherwise the preview understates how much a payment clears for a unit with credit.
  let remaining = totalMinor + creditMinor
  const rows = charges.map(({ charge, outstanding }) => {
    const applied = Math.min(remaining, outstanding)
    remaining -= applied
    return { charge, outstanding, applied }
  })
  return { rows, surplus: Math.max(0, remaining) }
}

function buildManualApplications(
  manualAmounts: Record<string, number>
): { charge_id: string; apply_amount: number }[] {
  return Object.entries(manualAmounts)
    .filter(([, amount]) => amount > 0)
    .map(([chargeId, amount]) => ({ charge_id: chargeId, apply_amount: amount }))
}

function sortByDueDate(a: ChargeRowInfo, b: ChargeRowInfo): number {
  return (a.charge.due_date ?? '').localeCompare(b.charge.due_date ?? '')
}

function coerceAmount(v: unknown): number {
  if (v === '' || v == null) return 0
  return Number(v)
}

function pickFile(e: React.ChangeEvent<HTMLInputElement>): File | null {
  return e.target.files?.[0] ?? null
}

// ---------------------------------------------------------------------------
// Custom hook — all form / data-fetching logic
// ---------------------------------------------------------------------------

function usePaymentForm(
  isOpen: boolean,
  presetUnitId: string | undefined,
  onClose: () => void,
  onRegistered?: () => void
): UsePaymentFormReturn {
  const queryClient = useQueryClient()
  const [receipt, setReceiptFile] = useState<File | null>(null)
  const [receiptError, setReceiptError] = useState<string | null>(null)
  const [manualAmounts, setManualAmounts] = useState<Record<string, number>>({})

  /** Fija el comprobante y limpia cualquier error previo (selección válida). */
  const setReceipt = useCallback((f: File | null) => {
    setReceiptFile(f)
    setReceiptError(null)
  }, [])

  const defaultValues = useMemo<RegisterPaymentValues>(
    () => buildDefaultValues(presetUnitId),
    [presetUnitId]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    setValue,
    watch,
    formState: { errors },
  } = useForm<RegisterPaymentValues>({
    resolver: zodResolver(registerPaymentSchema),
    defaultValues,
  })

  useEffect(() => {
    if (!isOpen) return
    reset(defaultValues)
    setReceiptFile(null)
    setReceiptError(null)
    setManualAmounts({})
  }, [isOpen, reset, defaultValues])

  const unitId = watch('unit_id')
  const totalPesos = watch('total_amount')
  const methodId = watch('payment_method_id')
  const applicationMode = watch('application_mode')
  const chargeType = watch('charge_type')
  const conceptMode = watch('concept_mode')
  const chargeConceptId = watch('charge_concept_id')
  const totalMinor = pesosToMinorUnits(totalPesos || 0)
  const isCash = methodId === PAYMENT_METHOD.CASH

  // En modo directo el pago va a un cargo nuevo ad-hoc, no a la deuda existente:
  // el estado de cuenta no se muestra ni se consulta (ahorro de infra).
  const { data: statement } = useQuery({
    queryKey: ['statement', unitId],
    queryFn: () => PaymentResource.statement(unitId),
    enabled: isOpen && Boolean(unitId) && chargeType === 'pending',
  })

  const pendingCharges = useMemo<ChargeRowInfo[]>(() => {
    if (!statement) return []
    return statement.charges
      .filter((c) => !c.is_cancelled)
      .map((c) => ({ charge: c, outstanding: chargeOutstanding(c) }))
      .filter((r) => r.outstanding > 0)
      .sort(sortByDueDate)
  }, [statement])

  const creditMinor = moneyToMinorUnits(statement?.credit_balance)

  const fifoPreview = useMemo<FifoPreview>(
    () => buildFifoPreview(pendingCharges, totalMinor, creditMinor),
    [pendingCharges, totalMinor, creditMinor]
  )

  // Deuda total de la unidad: la fuente canónica es `statement.totals`
  // (lo mismo que muestra el Estado de cuenta). Si por alguna razón no llega,
  // caemos a la suma de los saldos pendientes que ya tenemos calculados.
  const outstandingMinor = useMemo(() => {
    if (statement?.totals) return moneyToMinorUnits(statement.totals.outstanding)
    return pendingCharges.reduce((sum, r) => sum + r.outstanding, 0)
  }, [statement, pendingCharges])

  const manualTotal = useMemo(
    () => Object.values(manualAmounts).reduce((sum, v) => sum + v, 0),
    [manualAmounts]
  )

  const handleToggleCharge = useCallback(
    (chargeId: string, checked: boolean, outstanding: number) => {
      setManualAmounts((prev) => ({ ...prev, [chargeId]: checked ? outstanding : 0 }))
    },
    []
  )

  const handleAmountChange = useCallback((chargeId: string, pesos: number, outstanding: number) => {
    const minor =
      Number.isFinite(pesos) && pesos > 0 ? Math.min(pesosToMinorUnits(pesos), outstanding) : 0
    setManualAmounts((prev) => ({ ...prev, [chargeId]: minor }))
  }, [])

  const mutation = useMutation({
    mutationFn: async (values: RegisterPaymentValues): Promise<IPayment> => {
      if (values.charge_type === 'direct') {
        // Cobro directo: crea el cargo ad-hoc Y su pago anclado en una sola
        // acción atómica (POST /charge/direct). Nunca aplica FIFO.
        const isOther = values.concept_mode === 'other'
        const result = await ChargeResource.directCharge({
          unit_id: values.unit_id,
          charge_concept_id: isOther ? undefined : values.charge_concept_id || undefined,
          concept_free_text: isOther ? values.concept_free_text?.trim() : undefined,
          expected_amount: totalMinor,
          payment_method_id: values.payment_method_id,
          bank_account_id: values.bank_account_id,
          paid_at: values.paid_at || undefined,
          reference: values.reference || undefined,
          physical_receipt_number: values.physical_receipt_number || undefined,
          notes: values.notes || undefined,
          receipt,
        })
        return result.payment
      }
      const manualApplications =
        applicationMode === 'manual' ? buildManualApplications(manualAmounts) : undefined
      return PaymentResource.register({
        unit_id: values.unit_id,
        paid_at: values.paid_at,
        total_amount: totalMinor,
        payment_method_id: values.payment_method_id,
        bank_account_id: values.bank_account_id,
        payer_user_id: values.payer_user_id || undefined,
        reference: values.reference || undefined,
        physical_receipt_number: values.physical_receipt_number || undefined,
        notes: values.notes || undefined,
        receipt,
        manual_applications: manualApplications,
      })
    },
    onSuccess: (payment, values) => {
      const successLabel = values.charge_type === 'direct' ? 'Cobro registrado' : 'Pago registrado'
      toast.success(`${successLabel} · folio ${payment.receipt_number ?? ''}`)
      void queryClient.invalidateQueries({ queryKey: ['statement'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      void queryClient.invalidateQueries({ queryKey: ['charges'] })
      void queryClient.invalidateQueries({ queryKey: ['delinquency'] })
      onRegistered?.()
      onClose()
    },
    onError: (error, values) => {
      const fallback =
        values.charge_type === 'direct'
          ? 'No se pudo registrar el cobro directo'
          : 'No se pudo registrar el pago'
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : fallback)
    },
  })

  const submit = handleSubmit((values) => {
    if (!isCash && !receipt) {
      const message = 'El comprobante es obligatorio para métodos distintos a efectivo'
      setReceiptError(message)
      toast.error(message)
      return
    }
    if (
      values.charge_type === 'pending' &&
      values.application_mode === 'manual' &&
      manualTotal > totalMinor
    ) {
      toast.error('La aplicación manual excede el monto del pago')
      return
    }
    mutation.mutate(values)
  })

  return {
    register,
    control,
    errors,
    setValue,
    unitId,
    totalMinor,
    isCash,
    applicationMode,
    chargeType,
    conceptMode,
    chargeConceptId,
    receipt,
    setReceipt,
    receiptError,
    setReceiptError,
    manualAmounts,
    manualTotal,
    pendingCharges,
    fifoPreview,
    statement,
    outstandingMinor,
    handleToggleCharge,
    handleAmountChange,
    isPending: mutation.isPending,
    submit,
  }
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

interface FifoPreviewPanelProps {
  rows: FifoRow[]
  surplus: number
}

function FifoPreviewPanel({ rows, surplus }: FifoPreviewPanelProps) {
  return (
    <div className='rounded-[12px] border border-border bg-[var(--surface-2)] p-3'>
      <p className='mb-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Vista previa de aplicación
      </p>
      {rows.map(({ charge, outstanding, applied }) => (
        <div key={charge.id} className='flex items-center justify-between gap-2 py-1 text-[12.5px]'>
          <span className='min-w-0 truncate'>
            {charge.concept?.name ?? 'Cargo'}
            {charge.due_date ? ` · vence ${charge.due_date.slice(0, 10)}` : ''}
          </span>
          <span className='flex-none text-[var(--ink-3)]'>
            faltan {formatMinorUnits(outstanding)} →{' '}
            <b
              className={
                applied > 0 ? 'font-semibold text-[var(--green-deep)]' : 'text-[var(--ink-4)]'
              }
            >
              {formatMinorUnits(applied)}
            </b>
          </span>
        </div>
      ))}
      <div className='mt-2 flex justify-between border-t border-border pt-2 text-[12.5px] font-semibold'>
        <span>Saldo a favor restante</span>
        <span className='tabular-nums'>{formatMinorUnits(surplus)}</span>
      </div>
    </div>
  )
}

interface ManualRowProps {
  charge: ICharge
  outstanding: number
  applied: number
  onToggle: (id: string, checked: boolean, outstanding: number) => void
  onAmountChange: (id: string, pesos: number, outstanding: number) => void
}

function ManualRow({ charge, outstanding, applied, onToggle, onAmountChange }: ManualRowProps) {
  const chargeName = charge.concept?.name ?? 'cargo'
  return (
    <div className='flex items-center gap-2 py-1.5 text-[12.5px]'>
      <Checkbox
        checked={applied > 0}
        onCheckedChange={(checked) => onToggle(charge.id, checked === true, outstanding)}
        aria-label={`Aplicar a ${chargeName}`}
      />
      <span className='min-w-0 flex-1 truncate'>
        {chargeName}{' '}
        <span className='text-[var(--ink-4)]'>({formatMinorUnits(outstanding)} faltan)</span>
      </span>
      <div className='relative w-24 flex-none'>
        <span className='pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[11px] text-[var(--ink-3)]'>
          $
        </span>
        <Input
          id={`pay-apply-${charge.id}`}
          name={`pay-apply-${charge.id}`}
          type='number'
          step='0.01'
          min={0}
          className='h-7 pl-5 text-right text-[12px]'
          value={applied > 0 ? applied / 100 : ''}
          onChange={(e) => onAmountChange(charge.id, Number(e.target.value), outstanding)}
          aria-label={`Monto a aplicar a ${chargeName}`}
        />
      </div>
    </div>
  )
}

interface ManualPanelProps {
  pendingCharges: ChargeRowInfo[]
  manualAmounts: Record<string, number>
  manualTotal: number
  totalMinor: number
  onToggle: (id: string, checked: boolean, outstanding: number) => void
  onAmountChange: (id: string, pesos: number, outstanding: number) => void
}

function ManualPanel({
  pendingCharges,
  manualAmounts,
  manualTotal,
  totalMinor,
  onToggle,
  onAmountChange,
}: ManualPanelProps) {
  return (
    <div className='rounded-[12px] border border-border bg-[var(--surface-2)] p-3'>
      <p className='mb-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Aplicación manual
      </p>
      {pendingCharges.map(({ charge, outstanding }) => (
        <ManualRow
          key={charge.id}
          charge={charge}
          outstanding={outstanding}
          applied={manualAmounts[charge.id] ?? 0}
          onToggle={onToggle}
          onAmountChange={onAmountChange}
        />
      ))}
      <div className='mt-2 space-y-0.5 border-t border-border pt-2 text-[12.5px]'>
        <div className='flex justify-between'>
          <span>Total aplicado</span>
          <b className='font-semibold tabular-nums'>{formatMinorUnits(manualTotal)}</b>
        </div>
        <div className='flex justify-between text-[var(--ink-3)]'>
          <span>Saldo a favor</span>
          <span className='tabular-nums'>
            {formatMinorUnits(Math.max(0, totalMinor - manualTotal))}
          </span>
        </div>
      </div>
    </div>
  )
}

interface ChargePreviewSectionProps {
  unitId: string
  applicationMode: string
  pendingCharges: ChargeRowInfo[]
  fifoPreview: FifoPreview
  manualAmounts: Record<string, number>
  manualTotal: number
  totalMinor: number
  onToggle: (id: string, checked: boolean, outstanding: number) => void
  onAmountChange: (id: string, pesos: number, outstanding: number) => void
}

function ChargePreviewSection({
  unitId,
  applicationMode,
  pendingCharges,
  fifoPreview,
  manualAmounts,
  manualTotal,
  totalMinor,
  onToggle,
  onAmountChange,
}: ChargePreviewSectionProps) {
  if (unitId && pendingCharges.length === 0) {
    return (
      <p className='rounded-[10px] bg-[var(--surface-2)] px-3 py-2.5 text-[12.5px] text-[var(--ink-3)]'>
        La unidad no tiene cargos pendientes — todo el pago se registrará como saldo a favor.
      </p>
    )
  }
  if (applicationMode === 'auto' && pendingCharges.length > 0) {
    return <FifoPreviewPanel rows={fifoPreview.rows} surplus={fifoPreview.surplus} />
  }
  if (applicationMode === 'manual' && pendingCharges.length > 0) {
    return (
      <ManualPanel
        pendingCharges={pendingCharges}
        manualAmounts={manualAmounts}
        manualTotal={manualTotal}
        totalMinor={totalMinor}
        onToggle={onToggle}
        onAmountChange={onAmountChange}
      />
    )
  }
  return null
}

interface ReceiptFieldProps {
  fileRef: React.RefObject<HTMLInputElement | null>
  receipt: File | null
  isCash: boolean
  isPending: boolean
  /** Extensiones aceptadas — el modo directo restringe a las del backend. */
  accept: string
  error: string | null
  onFileChange: (e: React.ChangeEvent<HTMLInputElement>) => void
}

interface PaymentAfterHintProps {
  outstandingMinor: number
  totalMinor: number
  creditMinor: number
  applicationMode: string
}

/**
 * Hint en vivo "después de este pago…". Devuelve solo el contenido (sin
 * contenedor): se monta dentro del bloque de resumen, en su propio renglón —
 * nunca encimado al input de monto.
 */
function PaymentAfterHint({
  outstandingMinor,
  totalMinor,
  creditMinor,
  applicationMode,
}: PaymentAfterHintProps) {
  if (totalMinor <= 0 || outstandingMinor <= 0) return null
  // AUTO mode consumes the unit's existing credit FIRST, then the payment; manual mode
  // applies only the payment (credit is not auto-consumed), so count credit only in auto.
  const appliedPool = applicationMode === 'auto' ? totalMinor + creditMinor : totalMinor
  const remaining = outstandingMinor - appliedPool
  if (remaining > 0) {
    return (
      <>
        Después de este pago, debe{' '}
        <b className='font-semibold text-[#C0533C]'>{formatMinorUnits(remaining)}</b>.
      </>
    )
  }
  const surplus = -remaining
  return (
    <>
      Cubre toda la deuda
      {surplus > 0 && (
        <>
          {' · saldo a favor '}
          <b className='font-semibold text-[#3F6E8E]'>{formatMinorUnits(surplus)}</b>
        </>
      )}
      .
    </>
  )
}

function ReceiptField({
  fileRef,
  receipt,
  isCash,
  isPending,
  accept,
  error,
  onFileChange,
}: ReceiptFieldProps) {
  const receiptLabel = receipt ? receipt.name : 'Subir foto / PDF'
  return (
    <Field>
      <FieldLabel htmlFor='pay-receipt' required={!isCash}>
        Comprobante
      </FieldLabel>
      <input
        ref={fileRef}
        id='pay-receipt'
        type='file'
        accept={accept}
        className='hidden'
        onChange={onFileChange}
      />
      <Button
        type='button'
        variant='outline'
        className='justify-start'
        onClick={() => fileRef.current?.click()}
        disabled={isPending}
      >
        <Paperclip className='size-4' />
        {receiptLabel}
      </Button>
      {!isCash && (
        <FieldDescription>Obligatorio para transferencia, depósito y cheque.</FieldDescription>
      )}
      <FieldError>{error}</FieldError>
    </Field>
  )
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

/**
 * Panel deslizable "Registrar pago" (§8.5.1 + mock v3): captura completa con
 * vista previa FIFO sobre los cargos pendientes y aplicación manual opcional.
 * El sistema nunca rechaza por monto — el excedente se vuelve saldo a favor.
 *
 * Con `allowDirectCharge` (+ permisos create.charge Y create.payment) expone
 * además el modo "Cobro directo (servicio eventual)": genera un cargo puntual
 * y su pago anclado en una sola acción atómica (POST /charge/direct), sin
 * FIFO ni tabla de cargos pendientes.
 *
 * Shell delgado: early-return cuando está cerrado (gold standard §2.9). Así los
 * catálogos del formulario (PAYMENT_METHOD, BANK_ACCOUNT) NO disparan
 * `POST /selects` al montar la lista — el `useCatalogs` vive en el cuerpo, que
 * solo se monta cuando el panel está abierto.
 */
export function RegisterPaymentSheet({
  isOpen,
  onClose,
  presetUnitId,
  presetUnitLabel,
  allowDirectCharge,
  onRegistered,
}: RegisterPaymentSheetProps) {
  if (!isOpen) return null
  return (
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <SheetContent side='right' className='flex w-full flex-col gap-0 p-0 sm:max-w-[440px]'>
        <RegisterPaymentSheetBody
          isOpen={isOpen}
          onClose={onClose}
          presetUnitId={presetUnitId}
          presetUnitLabel={presetUnitLabel}
          allowDirectCharge={allowDirectCharge}
          onRegistered={onRegistered}
        />
      </SheetContent>
    </Sheet>
  )
}

/**
 * Cuerpo del panel: vive únicamente mientras el Sheet está montado (abierto), de
 * modo que `useCatalogs` y toda la lógica del formulario se ejecutan recién al
 * abrir el panel, no al montar la lista de pagos.
 */
function RegisterPaymentSheetBody({
  isOpen,
  onClose,
  presetUnitId,
  presetUnitLabel,
  allowDirectCharge,
  onRegistered,
}: RegisterPaymentSheetProps) {
  const fileRef = useRef<HTMLInputElement>(null)
  const { catalogs } = useCatalogs(useMemo(() => CATALOG_REQUEST, []))

  // POST /charge/direct requires BOTH create.charge AND create.payment
  // (ChargeController::direct). Checked individually because usePermissions
  // treats a string[] as OR (.some), so a single array call would let either
  // permission alone show the mode and then 403 on submit.
  const canCreateCharge = usePermissions('create.charge')
  const canCreatePayment = usePermissions('create.payment')
  const showDirectMode = Boolean(allowDirectCharge) && canCreateCharge && canCreatePayment

  const {
    register,
    control,
    errors,
    setValue,
    unitId,
    isCash,
    applicationMode,
    chargeType,
    conceptMode,
    chargeConceptId,
    receipt,
    setReceipt,
    receiptError,
    setReceiptError,
    manualAmounts,
    manualTotal,
    totalMinor,
    pendingCharges,
    fifoPreview,
    statement,
    outstandingMinor,
    handleToggleCharge,
    handleAmountChange,
    isPending,
    submit,
  } = usePaymentForm(isOpen, presetUnitId, onClose, onRegistered)

  const isDirect = chargeType === 'direct'
  const amountLabel = isDirect ? 'Monto' : 'Monto total'
  const submitLabel = isDirect ? 'Registrar cobro' : 'Registrar pago'

  const paymentMethods = (catalogs[CatalogType.PAYMENT_METHOD] ?? []) as CatalogItem[]
  const bankAccounts = (catalogs[CatalogType.BANK_ACCOUNT] ?? []) as CatalogItem[]
  // SearchSelect requiere `name: string`; los items del catálogo lo traen opcional.
  const bankAccountOptions = useMemo(
    () => bankAccounts.map((account) => ({ id: account.id, name: account.name ?? '' })),
    [bankAccounts]
  )
  const handleFileChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = pickFile(e)
      // El endpoint directo rechaza comprobantes > 10 MB (max:10240 KB).
      if (isDirect && file && file.size > MAX_RECEIPT_SIZE_BYTES) {
        setReceiptError('El comprobante no debe superar 10 MB')
        e.target.value = ''
        return
      }
      setReceipt(file)
    },
    [isDirect, setReceipt, setReceiptError]
  )
  const handleUnitSelect = useCallback(
    (unit: { id: string }) => {
      setValue('unit_id', unit.id, { shouldValidate: true })
    },
    [setValue]
  )

  return (
    <>
      <SheetHeader className='border-b border-[var(--line-2)] px-5 py-4'>
        <SheetTitle>Registrar pago</SheetTitle>
        <SheetDescription>
          Registra un pago confirmado y aplícalo a los cargos de la unidad.
        </SheetDescription>
      </SheetHeader>

      <form className='min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4' onSubmit={submit}>
        <RequiredFieldsLegend />
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor='pay-unit' required>
              Unidad
            </FieldLabel>
            <UnitCombobox
              id='pay-unit'
              value={unitId}
              initialLabel={presetUnitLabel}
              disabled={isPending || Boolean(presetUnitId)}
              onSelect={handleUnitSelect}
            />
            <FieldError>{errors.unit_id?.message}</FieldError>
          </Field>

          {showDirectMode && (
            <Field>
              <FieldLabel>Tipo de cobro</FieldLabel>
              <Controller
                name='charge_type'
                control={control}
                render={({ field }) => (
                  <RadioGroup
                    aria-label='Tipo de cobro'
                    value={field.value}
                    onValueChange={field.onChange}
                    disabled={isPending}
                    className='grid grid-cols-2 gap-2'
                  >
                    <label className='flex cursor-pointer items-center gap-2 rounded-[10px] border border-border px-3 py-2 text-[13px] has-[[data-state=checked]]:border-[var(--green)] has-[[data-state=checked]]:bg-[var(--green-bg)]'>
                      <RadioGroupItem value='pending' />
                      Cargos pendientes
                    </label>
                    <label className='flex cursor-pointer items-center gap-2 rounded-[10px] border border-border px-3 py-2 text-[13px] has-[[data-state=checked]]:border-[var(--green)] has-[[data-state=checked]]:bg-[var(--green-bg)]'>
                      <RadioGroupItem value='direct' />
                      Cobro directo (servicio eventual)
                    </label>
                  </RadioGroup>
                )}
              />
              {isDirect && (
                <FieldDescription>
                  Genera un cargo puntual y registra su pago en una sola acción.
                </FieldDescription>
              )}
            </Field>
          )}

          {isDirect && (
            <DirectChargeFields
              conceptMode={conceptMode}
              chargeConceptId={chargeConceptId}
              errors={errors}
              register={register}
              setValue={setValue}
              isPending={isPending}
            />
          )}

          {!isDirect && unitId && statement && (
            <UnitDebtSummary
              outstandingMinor={outstandingMinor}
              creditMinor={moneyToMinorUnits(statement.credit_balance)}
              pendingCount={pendingCharges.length}
              afterHint={
                <PaymentAfterHint
                  outstandingMinor={outstandingMinor}
                  totalMinor={totalMinor}
                  creditMinor={moneyToMinorUnits(statement.credit_balance)}
                  applicationMode={applicationMode}
                />
              }
            />
          )}

          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='pay-date' required>
                Fecha de pago
              </FieldLabel>
              <Input id='pay-date' type='date' {...register('paid_at')} disabled={isPending} />
              <FieldError>{errors.paid_at?.message}</FieldError>
            </Field>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='pay-amount' required>
                {amountLabel}
              </FieldLabel>
              <div className='relative'>
                <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                  $
                </span>
                <Input
                  id='pay-amount'
                  type='number'
                  step='0.01'
                  min={0}
                  placeholder='0.00'
                  className='pl-7'
                  {...register('total_amount', { setValueAs: coerceAmount })}
                  disabled={isPending}
                />
              </div>
              <FieldError>{errors.total_amount?.message}</FieldError>
            </Field>
          </div>

          <Field>
            <FieldLabel required>Método de pago</FieldLabel>
            <Controller
              name='payment_method_id'
              control={control}
              render={({ field }) => (
                <RadioGroup
                  aria-label='Método de pago'
                  value={field.value}
                  onValueChange={field.onChange}
                  disabled={isPending}
                  className='grid grid-cols-2 gap-2'
                >
                  {paymentMethods.map((method) => (
                    <label
                      key={method.id}
                      className='flex cursor-pointer items-center gap-2 rounded-[10px] border border-border px-3 py-2 text-[13px] has-[[data-state=checked]]:border-[var(--green)] has-[[data-state=checked]]:bg-[var(--green-bg)]'
                    >
                      <RadioGroupItem value={method.id} />
                      {method.name}
                    </label>
                  ))}
                </RadioGroup>
              )}
            />
            <FieldError>{errors.payment_method_id?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor='pay-account' required>
              Cuenta destino
            </FieldLabel>
            <Controller
              name='bank_account_id'
              control={control}
              render={({ field }) => (
                <SearchSelect
                  id='pay-account'
                  value={field.value}
                  onChange={(v) => field.onChange((v as string | null) ?? '')}
                  options={bankAccountOptions}
                  isDisabled={isPending}
                  invalid={!!errors.bank_account_id}
                  placeholder='Seleccionar cuenta…'
                />
              )}
            />
            <FieldError>{errors.bank_account_id?.message}</FieldError>
          </Field>

          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='pay-ref'>Referencia</FieldLabel>
              <Input
                id='pay-ref'
                placeholder='Núm. de operación'
                {...register('reference')}
                disabled={isPending}
              />
            </Field>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='pay-folio'>Folio recibo físico</FieldLabel>
              <Input
                id='pay-folio'
                placeholder='0024'
                {...register('physical_receipt_number')}
                disabled={isPending}
              />
            </Field>
          </div>

          <ReceiptField
            fileRef={fileRef}
            receipt={receipt}
            isCash={isCash}
            isPending={isPending}
            accept={isDirect ? DIRECT_RECEIPT_ACCEPT : 'image/*,.pdf'}
            error={receiptError}
            onFileChange={handleFileChange}
          />

          {/* Aplicación FIFO/manual y previews: solo aplican al flujo sobre
              cargos existentes — el cobro directo va a su propio cargo ad-hoc. */}
          {!isDirect && (
            <>
              <Field>
                <FieldLabel>Aplicación a cargos</FieldLabel>
                <Controller
                  name='application_mode'
                  control={control}
                  render={({ field }) => (
                    <RadioGroup
                      aria-label='Aplicación a cargos'
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={isPending}
                      className='flex gap-4'
                    >
                      <label className='flex cursor-pointer items-center gap-2 text-[13px]'>
                        <RadioGroupItem value='auto' />
                        Automática (FIFO)
                      </label>
                      <label className='flex cursor-pointer items-center gap-2 text-[13px]'>
                        <RadioGroupItem value='manual' />
                        Manual
                      </label>
                    </RadioGroup>
                  )}
                />
              </Field>

              <ChargePreviewSection
                unitId={unitId}
                applicationMode={applicationMode}
                pendingCharges={pendingCharges}
                fifoPreview={fifoPreview}
                manualAmounts={manualAmounts}
                manualTotal={manualTotal}
                totalMinor={totalMinor}
                onToggle={handleToggleCharge}
                onAmountChange={handleAmountChange}
              />

              {statement && moneyToMinorUnits(statement.credit_balance) > 0 && (
                <p className='text-[12px] text-[var(--ink-3)]'>
                  Saldo a favor actual de la unidad:{' '}
                  <b className='font-semibold text-[#3F6E8E]'>
                    {displayMoney(statement.credit_balance)}
                  </b>{' '}
                  — se consume automáticamente antes del pago.
                </p>
              )}
            </>
          )}

          <Field>
            <FieldLabel htmlFor='pay-notes'>Notas</FieldLabel>
            <Textarea
              id='pay-notes'
              rows={2}
              className='resize-none'
              {...register('notes')}
              disabled={isPending}
            />
          </Field>
        </FieldGroup>
      </form>

      <SheetFooter className='border-t border-[var(--line-2)] px-5 py-4'>
        <div className='flex w-full gap-2'>
          <Button
            type='button'
            variant='outline'
            className='flex-1 rounded-full'
            onClick={onClose}
            disabled={isPending}
          >
            Cancelar
          </Button>
          <Button
            type='button'
            className='flex-1 rounded-full'
            onClick={() => void submit()}
            disabled={isPending}
          >
            {isPending ? 'Registrando…' : submitLabel}
          </Button>
        </div>
      </SheetFooter>
    </>
  )
}
