import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { AmenityAvailabilityCalendar } from '@/modules/amenity/components/AmenityAvailabilityCalendar'
import type { IAmenity } from '@/modules/amenity/schema'
import { useMyRestrictions } from '@/modules/collections/hooks/useMyRestrictions'
import { SubmitReservationPaymentSheet } from '@/modules/reservation/components/SubmitReservationPaymentSheet'
import {
  buildReserveWizardSteps,
  canProceedFromDateTimeStep,
  canProceedFromRulesStep,
  costInputsFromAmenity,
  isBySlotAmenity,
  reserveDepositHint,
  resolvePostCreateOutcome,
  buildReservationCreatePayload,
} from '@/modules/reservation/reserveWizard'
import type { IAmenityReservation } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import type { IUnit } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate, formatTimeHHmm } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

export interface ReserveAmenityWizardProps {
  isOpen: boolean
  onClose: () => void
  amenity: IAmenity
  /** Fecha/horario ya elegidos en la vista de exploración (S14) — precargan el paso "fecha/horario". */
  initialDate?: string | null
  initialSlotId?: string | null
}

function unitLabel(unit: IUnit): string {
  return unit.full_identifier ?? unit.number ?? 'Unidad'
}

// ─── steps ────────────────────────────────────────────────────────────────────

function UnitStep({
  units,
  selectedUnitId,
  onSelect,
}: {
  units: IUnit[]
  selectedUnitId: string
  onSelect: (_id: string) => void
}) {
  return (
    <div className='space-y-2'>
      <p className='text-[13px] text-[var(--ink-3)]'>
        ¿Para cuál de tus unidades es la reservación?
      </p>
      {units.map((unit) => (
        <label
          key={unit.id}
          className='flex cursor-pointer items-center gap-2.5 rounded-[10px] border border-border px-3 py-2.5 text-[13.5px] has-[input:checked]:border-[var(--green)] has-[input:checked]:bg-[var(--green-bg)]'
        >
          <input
            type='radio'
            name='reserve-unit'
            className='size-4'
            checked={selectedUnitId === unit.id}
            onChange={() => onSelect(unit.id)}
          />
          {unitLabel(unit)}
        </label>
      ))}
    </div>
  )
}

function RulesStep({
  rulesText,
  accepted,
  onChange,
}: {
  rulesText: string
  accepted: boolean
  onChange: (_v: boolean) => void
}) {
  return (
    <div className='space-y-3'>
      <div className='max-h-64 overflow-y-auto rounded-[10px] border border-border bg-[var(--surface-2)] p-3 text-[13px] whitespace-pre-line text-foreground'>
        {rulesText}
      </div>
      <label className='flex cursor-pointer items-start gap-2.5 text-[13px]'>
        <Checkbox
          checked={accepted}
          onCheckedChange={(v) => onChange(v === true)}
          className='mt-0.5'
        />
        He leído y acepto el reglamento de esta amenidad.
      </label>
    </div>
  )
}

interface ReviewStepProps {
  amenity: IAmenity
  unit?: IUnit
  date: string
  slotLabel?: string
}

function ReviewStep({ amenity, unit, date, slotLabel }: ReviewStepProps) {
  const hint = reserveDepositHint(costInputsFromAmenity(amenity))

  return (
    <dl className='space-y-3 text-[13.5px]'>
      <div className='flex items-center justify-between gap-3'>
        <dt className='text-[var(--ink-3)]'>Amenidad</dt>
        <dd className='font-medium text-foreground'>{amenity.name}</dd>
      </div>
      {unit && (
        <div className='flex items-center justify-between gap-3'>
          <dt className='text-[var(--ink-3)]'>Unidad</dt>
          <dd className='font-medium text-foreground'>{unitLabel(unit)}</dd>
        </div>
      )}
      <div className='flex items-center justify-between gap-3'>
        <dt className='text-[var(--ink-3)]'>Fecha</dt>
        <dd className='font-medium text-foreground'>{displayFormatDate(date, 'mediumDate')}</dd>
      </div>
      {slotLabel && (
        <div className='flex items-center justify-between gap-3'>
          <dt className='text-[var(--ink-3)]'>Horario</dt>
          <dd className='font-medium text-foreground'>{slotLabel}</dd>
        </div>
      )}
      {amenity.requires_approval && (
        <p className='rounded-[10px] border border-[#F0E4C6] bg-[#FBF6E9] px-3 py-2 text-[12.5px] text-[#8A6D1F]'>
          Esta reservación necesita aprobación del administrador antes de confirmarse.
        </p>
      )}
      {hint ? (
        <div className='rounded-[10px] border border-border bg-[var(--surface-2)] px-3 py-2.5'>
          {hint.isPartial ? (
            <>
              <div className='flex items-center justify-between'>
                <span className='text-[var(--ink-3)]'>Depósito mínimo</span>
                <span className='font-semibold'>{displayMoney(hint.minimumMinor)}</span>
              </div>
              <div className='mt-1 flex items-center justify-between'>
                <span className='text-[var(--ink-3)]'>Costo total</span>
                <span className='font-semibold'>{displayMoney(hint.totalMinor)}</span>
              </div>
              <p className='mt-1.5 text-[12px] text-[var(--ink-3)]'>
                Puedes pagar solo el depósito ahora y liquidar el saldo después.
              </p>
            </>
          ) : (
            <div className='flex items-center justify-between'>
              <span className='text-[var(--ink-3)]'>Costo</span>
              <span className='font-semibold'>{displayMoney(hint.totalMinor)}</span>
            </div>
          )}
        </div>
      ) : (
        <p className='rounded-[10px] border border-[#D6E4DC] bg-[#EDF5F0] px-3 py-2 text-[12.5px] text-[#3F9A5E]'>
          Esta amenidad no tiene costo.
        </p>
      )}
    </dl>
  )
}

const STEP_TITLE: Record<string, string> = {
  unit: 'Elige tu unidad',
  datetime: 'Elige fecha y horario',
  rules: 'Reglamento',
  review: 'Revisa y confirma',
}

// ─── body ─────────────────────────────────────────────────────────────────────

function ReserveAmenityWizardBody({
  onClose,
  amenity,
  initialDate,
  initialSlotId,
}: Omit<ReserveAmenityWizardProps, 'isOpen'>) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { unitState, notice } = useMyRestrictions()

  const { data: ownUnits = [] } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })

  const steps = useMemo(
    () => buildReserveWizardSteps(amenity, ownUnits.length),
    [amenity, ownUnits.length]
  )
  const [stepIndex, setStepIndex] = useState(0)
  const currentStep = steps[stepIndex] ?? 'review'

  // Derivado, no estado: con una sola unidad propia se preselecciona sola; con
  // más de una, `manualUnitId` guarda lo que el residente elige en `UnitStep`.
  // Evita un efecto solo para sincronizar un default (cascading setState).
  const [manualUnitId, setManualUnitId] = useState<string | null>(null)
  const defaultUnitId = ownUnits.length === 1 ? ownUnits[0].id : ''
  const unitId = manualUnitId ?? defaultUnitId

  // Delinquency restriction (design §8.2, R-DELQ-1): gating is on THIS unit
  // only, never an aggregate — a resident with unit A current + unit B
  // OVERDUE must still be able to reserve for A.
  const restriction = unitId ? unitState(unitId) : undefined
  const isAmenityRestricted = restriction?.features.amenities === true

  const [selectedDate, setSelectedDate] = useState<string | null>(initialDate ?? null)
  const [selectedSlotId, setSelectedSlotId] = useState<string | null>(initialSlotId ?? null)
  const [rulesAccepted, setRulesAccepted] = useState(false)
  const [phase, setPhase] = useState<'wizard' | 'payment'>('wizard')
  const [createdReservation, setCreatedReservation] = useState<IAmenityReservation | null>(null)

  const finish = () => {
    onClose()
    navigate('/my-reservations')
  }

  const mutation = useMutation({
    mutationFn: () =>
      AmenityReservationResource.create(
        buildReservationCreatePayload({
          amenityId: amenity.id,
          unitId,
          reservationDate: selectedDate ?? '',
          slotId: selectedSlotId,
          rulesAccepted,
        })
      ),
    onSuccess: (reservation) => {
      void queryClient.invalidateQueries({ queryKey: ['amenity-reservation'] })
      void queryClient.invalidateQueries({ queryKey: ['amenity-availability', amenity.id] })

      // BUG FIX (E2E QA round 2): branch on the reservation status the
      // BACKEND actually returned, not `amenity.has_cost`. A costed amenity
      // that ALSO `requires_approval` creates the reservation as
      // `PENDING_APPROVAL`, which the backend rejects for payment (422 "no
      // admite pagos en su estado actual") — auto-opening the payment sheet
      // there was the bug. Only `PENDING_PAYMENT` may chain into it.
      const outcome = resolvePostCreateOutcome(reservation.reservation_status_id)

      if (outcome === 'payment') {
        // BUG FIX (E2E QA): a toast here doesn't auto-dismiss before the
        // payment sheet auto-opens, overlapping it and intercepting clicks on
        // "Enviar pago"/"Pagar después". The sheet's own header ("Pagar
        // reservación") already communicates the outcome, so skip the toast
        // entirely on this branch instead of racing a dismiss against it.
        setCreatedReservation(reservation)
        setPhase('payment')
        return
      }

      if (outcome === 'approval_pending') {
        toast.success(
          'Tu reservación quedó pendiente de aprobación. Podrás pagarla cuando sea aprobada.'
        )
      } else {
        toast.success('Reservación creada')
      }
      finish()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo crear la reservación'
      )
    },
  })

  const canProceed = (() => {
    // A restricted unit never advances past its current step (design §8.2) —
    // the resident must pick a different unit or settle the debt first. The
    // backend 403 remains the authority if this client state goes stale.
    if (isAmenityRestricted) return false
    switch (currentStep) {
      case 'unit':
        return Boolean(unitId)
      case 'datetime':
        return canProceedFromDateTimeStep(amenity, selectedDate, selectedSlotId)
      case 'rules':
        return canProceedFromRulesStep(rulesAccepted)
      default:
        return true
    }
  })()

  const isLastStep = stepIndex === steps.length - 1
  const selectedUnit = ownUnits.find((u) => u.id === unitId)
  const selectedSlot =
    isBySlotAmenity(amenity) && selectedSlotId
      ? amenity.slots?.find((s) => s.id === selectedSlotId)
      : undefined

  return (
    <>
      <Sheet open={phase === 'wizard'} onOpenChange={(open) => !open && onClose()}>
        <SheetContent side='right' className='flex w-full flex-col gap-0 p-0 sm:max-w-[440px]'>
          <SheetHeader className='border-b border-[var(--line-2)] px-5 py-4'>
            <div className='mb-2 flex items-center gap-2'>
              <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                Paso {stepIndex + 1} de {steps.length}
              </span>
              <div className='flex flex-1 gap-1' aria-hidden>
                {steps.map((step, i) => (
                  <span
                    key={step}
                    className={
                      i <= stepIndex
                        ? 'h-1 flex-1 rounded-full bg-[var(--green)]'
                        : 'h-1 flex-1 rounded-full bg-[var(--line-2)]'
                    }
                  />
                ))}
              </div>
            </div>
            {/* While restricted the step body is hidden, so the step title would be
                promising a choice that is not on screen ("Elige fecha y horario" with
                nothing to pick). Say what is actually happening instead. */}
            <SheetTitle>
              {isAmenityRestricted && currentStep !== 'unit'
                ? 'Reserva no disponible'
                : STEP_TITLE[currentStep]}
            </SheetTitle>
            <SheetDescription>{amenity.name}</SheetDescription>
          </SheetHeader>

          <div className='min-h-0 flex-1 overflow-y-auto px-5 py-4'>
            {isAmenityRestricted && (
              <div
                role='alert'
                className='mb-4 flex items-start gap-2 rounded-lg bg-destructive/5 p-3 text-sm text-destructive'
              >
                <AlertCircle className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
                <div className='space-y-1'>
                  <p className='font-medium'>
                    {restriction?.overdue_balance
                      ? `Adeudo vencido: ${displayMoney(restriction.overdue_balance)}.`
                      : 'Esta unidad tiene un adeudo vencido.'}
                  </p>
                  {notice && <p>{notice}</p>}
                  <p>Realiza tu pago para reactivar las reservas.</p>
                </div>
              </div>
            )}
            {/* The unit step ALWAYS renders, even while restricted: a resident holding
                several units must be able to switch to one that is current (R-DELQ-1).
                Every other step is hidden while restricted — the reservation cannot be
                submitted, so showing a calendar and a rules checkbox only wastes time. */}
            {currentStep === 'unit' && (
              <UnitStep units={ownUnits} selectedUnitId={unitId} onSelect={setManualUnitId} />
            )}
            {!isAmenityRestricted && currentStep === 'datetime' && (
              <AmenityAvailabilityCalendar
                amenity={amenity}
                selectedDate={selectedDate}
                onSelectDate={(date) => {
                  setSelectedDate(date)
                  setSelectedSlotId(null)
                }}
                selectedSlotId={selectedSlotId}
                onSelectSlot={setSelectedSlotId}
              />
            )}
            {!isAmenityRestricted && currentStep === 'rules' && (
              <RulesStep
                rulesText={amenity.rules_text ?? ''}
                accepted={rulesAccepted}
                onChange={setRulesAccepted}
              />
            )}
            {!isAmenityRestricted && currentStep === 'review' && (
              <ReviewStep
                amenity={amenity}
                unit={selectedUnit}
                date={selectedDate ?? ''}
                slotLabel={
                  selectedSlot
                    ? `${formatTimeHHmm(selectedSlot.start_time)} – ${formatTimeHHmm(selectedSlot.end_time)}`
                    : undefined
                }
              />
            )}
          </div>

          <SheetFooter className='border-t border-[var(--line-2)] px-5 py-4'>
            <div className='flex w-full gap-2'>
              <Button
                type='button'
                variant='outline'
                className='flex-1 rounded-full'
                onClick={stepIndex === 0 ? onClose : () => setStepIndex((i) => Math.max(i - 1, 0))}
                disabled={mutation.isPending}
              >
                {stepIndex === 0 ? 'Cancelar' : 'Atrás'}
              </Button>
              {isLastStep ? (
                <Button
                  type='button'
                  className='flex-1 rounded-full'
                  onClick={() => mutation.mutate()}
                  disabled={mutation.isPending || isAmenityRestricted}
                >
                  {mutation.isPending ? 'Reservando…' : 'Confirmar reservación'}
                </Button>
              ) : (
                <Button
                  type='button'
                  className='flex-1 rounded-full'
                  onClick={() => setStepIndex((i) => Math.min(i + 1, steps.length - 1))}
                  disabled={!canProceed}
                >
                  Siguiente
                </Button>
              )}
            </div>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {createdReservation && (
        <SubmitReservationPaymentSheet
          isOpen={phase === 'payment'}
          onClose={finish}
          reservation={createdReservation}
          amenity={amenity}
          onSubmitted={finish}
        />
      )}
    </>
  )
}

/**
 * Wizard de reservación de amenidad (S15): unidad (si el residente tiene más
 * de una) → fecha/horario (calendario compartido con S14) → reglamento (si
 * la amenidad lo tiene) → revisión → confirmar (`POST /amenity_reservation`).
 * Si la amenidad tiene costo, encadena `SubmitReservationPaymentSheet`
 * (earmark) prefijado con la cuenta destino de la amenidad; si no, navega
 * directo a "Mis reservaciones".
 */
export function ReserveAmenityWizard({
  isOpen,
  onClose,
  amenity,
  initialDate,
  initialSlotId,
}: ReserveAmenityWizardProps) {
  if (!isOpen) return null
  return (
    <ReserveAmenityWizardBody
      onClose={onClose}
      amenity={amenity}
      initialDate={initialDate}
      initialSlotId={initialSlotId}
    />
  )
}
