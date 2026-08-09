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
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { RegisterReservationPaymentSheet } from '@/modules/reservation/components/RegisterReservationPaymentSheet'
import {
  buildReservationCreatePayload,
  canProceedFromDateTimeStep,
  canProceedFromRulesStep,
  costInputsFromAmenity,
  reserveDepositHint,
  resolvePostCreateOutcome,
} from '@/modules/reservation/reserveWizard'
import type { IAmenityReservation } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import type { IUnit } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

export interface AdminCreateReservationSheetProps {
  isOpen: boolean
  onClose: () => void
}

type AdminReserveStep = 'amenity' | 'unit' | 'datetime' | 'rules' | 'review'

const STEP_TITLE: Record<AdminReserveStep, string> = {
  amenity: 'Elige la amenidad',
  unit: 'Elige la unidad',
  datetime: 'Elige fecha y horario',
  rules: 'Reglamento',
  review: 'Revisa y confirma',
}

function unitLabel(unit: Pick<IUnit, 'full_identifier' | 'number' | 'id'>): string {
  return unit.full_identifier ?? unit.number ?? unit.id
}

/** Pasos del alta admin: `amenity` siempre primero (a diferencia del wizard del residente,
 * que la recibe como prop); `rules` solo si la amenidad elegida tiene reglamento. */
function buildAdminSteps(amenity: Pick<IAmenity, 'rules_text'> | null): AdminReserveStep[] {
  const steps: AdminReserveStep[] = ['amenity']
  if (!amenity) return steps
  steps.push('unit', 'datetime')
  if (amenity.rules_text) steps.push('rules')
  steps.push('review')
  return steps
}

// ─── steps ────────────────────────────────────────────────────────────────────

interface AmenityStepProps {
  value: string
  onSelect: (_id: string) => void
  cache: Record<string, IAmenity>
  onCacheUpdate: (_updates: Record<string, IAmenity>) => void
}

function AmenityStep({ value, onSelect, cache, onCacheUpdate }: AmenityStepProps) {
  const loadAmenities = async (term: string): Promise<IAmenity[]> => {
    const { data } = await AmenityResource.search({
      search: { value: term },
      limit: 20,
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [{ relation: 'bank_account' }],
    })
    const updates: Record<string, IAmenity> = {}
    for (const amenity of data) updates[amenity.id] = amenity
    onCacheUpdate(updates)
    return data
  }

  return (
    <div className='space-y-2'>
      <p className='text-[13px] text-[var(--ink-3)]'>¿Para qué amenidad es esta reservación?</p>
      <SearchSelect<IAmenity>
        id='admin-reserve-amenity'
        value={value}
        onChange={(v) => onSelect((v as string | null) ?? '')}
        loadOptions={loadAmenities}
        selectedOptions={value && cache[value] ? [cache[value]] : []}
        placeholder='Buscar amenidad…'
        noOptionsMessage='Escribe para buscar amenidades'
      />
    </div>
  )
}

interface UnitStepProps {
  value: string
  onSelect: (_id: string) => void
  cache: Record<string, IUnit>
  onCacheUpdate: (_updates: Record<string, IUnit>) => void
}

function UnitStep({ value, onSelect, cache, onCacheUpdate }: UnitStepProps) {
  const loadUnits = async (term: string): Promise<IUnit[]> => {
    const { data } = await UnitResource.search({
      search: { value: term },
      limit: 20,
      sort: [{ field: 'number', direction: 'asc' }],
    })
    const updates: Record<string, IUnit> = {}
    for (const unit of data) updates[unit.id] = unit
    onCacheUpdate(updates)
    return data
  }

  return (
    <div className='space-y-2'>
      <p className='text-[13px] text-[var(--ink-3)]'>
        ¿Para cuál unidad del condominio es esta reservación?
      </p>
      <SearchSelect<IUnit>
        id='admin-reserve-unit'
        value={value}
        onChange={(v) => onSelect((v as string | null) ?? '')}
        loadOptions={loadUnits}
        selectedOptions={value && cache[value] ? [cache[value]] : []}
        getOptionLabel={unitLabel}
        placeholder='Buscar unidad…'
        noOptionsMessage='Escribe para buscar unidades'
      />
      <p className='text-[12px] text-[var(--ink-3)]'>
        La unidad debe estar dentro del alcance configurado para esta amenidad; de lo contrario el
        servidor rechazará la reservación.
      </p>
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
        Confirmo que la unidad aceptó el reglamento de esta amenidad.
      </label>
    </div>
  )
}

interface ReviewStepProps {
  amenity: IAmenity
  unit?: IUnit
  date: string
}

function ReviewStep({ amenity, unit, date }: ReviewStepProps) {
  const hint = reserveDepositHint(costInputsFromAmenity(amenity))

  return (
    <dl className='space-y-3 text-[13.5px]'>
      <div className='flex items-center justify-between gap-3'>
        <dt className='text-[var(--ink-3)]'>Amenidad</dt>
        <dd className='font-medium text-foreground'>{amenity.name}</dd>
      </div>
      <div className='flex items-center justify-between gap-3'>
        <dt className='text-[var(--ink-3)]'>Unidad</dt>
        <dd className='font-medium text-foreground'>{unit ? unitLabel(unit) : '—'}</dd>
      </div>
      <div className='flex items-center justify-between gap-3'>
        <dt className='text-[var(--ink-3)]'>Fecha</dt>
        <dd className='font-medium text-foreground'>{displayFormatDate(date, 'mediumDate')}</dd>
      </div>
      {amenity.requires_approval && (
        <p className='rounded-[10px] border border-[#F0E4C6] bg-[#FBF6E9] px-3 py-2 text-[12.5px] text-[#8A6D1F]'>
          Esta reservación necesita aprobación antes de confirmarse.
        </p>
      )}
      {hint ? (
        <div className='rounded-[10px] border border-border bg-[var(--surface-2)] px-3 py-2.5'>
          <div className='flex items-center justify-between'>
            <span className='text-[var(--ink-3)]'>Costo</span>
            <span className='font-semibold'>{displayMoney(hint.totalMinor)}</span>
          </div>
          {hint.isPartial && (
            <div className='mt-1 flex items-center justify-between'>
              <span className='text-[var(--ink-3)]'>Depósito mínimo</span>
              <span className='font-semibold'>{displayMoney(hint.minimumMinor)}</span>
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

// ─── body ─────────────────────────────────────────────────────────────────────

function AdminCreateReservationSheetBody({
  onClose,
}: Omit<AdminCreateReservationSheetProps, 'isOpen'>) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [amenityId, setAmenityId] = useState('')
  const [amenityCache, setAmenityCache] = useState<Record<string, IAmenity>>({})
  const [unitId, setUnitId] = useState('')
  const [unitCache, setUnitCache] = useState<Record<string, IUnit>>({})
  const [selectedDate, setSelectedDate] = useState<string | null>(null)
  const [selectedSlotId, setSelectedSlotId] = useState<string | null>(null)
  const [rulesAccepted, setRulesAccepted] = useState(false)
  const [stepIndex, setStepIndex] = useState(0)
  const [phase, setPhase] = useState<'wizard' | 'payment'>('wizard')
  const [createdReservation, setCreatedReservation] = useState<IAmenityReservation | null>(null)

  const selectedAmenity = amenityId ? (amenityCache[amenityId] ?? null) : null
  const selectedUnit = unitId ? unitCache[unitId] : undefined

  const steps = useMemo(() => buildAdminSteps(selectedAmenity), [selectedAmenity])
  const currentStep = steps[stepIndex] ?? 'amenity'
  const isLastStep = stepIndex === steps.length - 1

  // Cambiar de amenidad invalida las elecciones que dependían de ella (la unidad puede
  // quedar fuera del nuevo alcance, la fecha/horario responde a la disponibilidad de la
  // amenidad anterior) — se resetean para forzar una elección consciente en vez de dejar
  // pasar una combinación stale hasta el 422 del backend.
  const selectAmenity = (id: string) => {
    setAmenityId(id)
    setUnitId('')
    setSelectedDate(null)
    setSelectedSlotId(null)
    setRulesAccepted(false)
  }

  const finish = (reservation: IAmenityReservation) => {
    onClose()
    navigate(`/amenity-reservation/${reservation.id}`)
  }

  const mutation = useMutation({
    mutationFn: () =>
      AmenityReservationResource.create(
        buildReservationCreatePayload({
          amenityId,
          unitId,
          reservationDate: selectedDate ?? '',
          slotId: selectedSlotId,
          rulesAccepted,
        })
      ),
    onSuccess: (reservation) => {
      void queryClient.invalidateQueries({ queryKey: ['amenity-reservation'] })
      if (selectedAmenity) {
        void queryClient.invalidateQueries({
          queryKey: ['amenity-availability', selectedAmenity.id],
        })
      }

      // Igual que en ReserveAmenityWizard (residente): el estatus que devolvió el
      // BACKEND manda, no la config de la amenidad — una amenidad con costo cuya
      // reservación cae en PENDING_APPROVAL (porque también requires_approval) no
      // admite pago todavía.
      const outcome = resolvePostCreateOutcome(reservation.reservation_status_id)

      if (outcome === 'payment' && selectedAmenity) {
        setCreatedReservation(reservation)
        setPhase('payment')
        return
      }

      if (outcome === 'approval_pending') {
        toast.success(
          'La reservación quedó pendiente de aprobación. Podrás registrar el pago cuando sea aprobada.'
        )
      } else {
        toast.success('Reservación creada')
      }
      finish(reservation)
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo crear la reservación'
      )
    },
  })

  const canProceed = (() => {
    switch (currentStep) {
      case 'amenity':
        return Boolean(amenityId)
      case 'unit':
        return Boolean(unitId)
      case 'datetime':
        return selectedAmenity
          ? canProceedFromDateTimeStep(selectedAmenity, selectedDate, selectedSlotId)
          : false
      case 'rules':
        return canProceedFromRulesStep(rulesAccepted)
      default:
        return true
    }
  })()

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
            <SheetTitle>{STEP_TITLE[currentStep]}</SheetTitle>
            <SheetDescription>Nueva reservación a nombre de una unidad</SheetDescription>
          </SheetHeader>

          <div className='min-h-0 flex-1 overflow-y-auto px-5 py-4'>
            {currentStep === 'amenity' && (
              <AmenityStep
                value={amenityId}
                onSelect={selectAmenity}
                cache={amenityCache}
                onCacheUpdate={(updates) => setAmenityCache((prev) => ({ ...prev, ...updates }))}
              />
            )}
            {currentStep === 'unit' && (
              <UnitStep
                value={unitId}
                onSelect={setUnitId}
                cache={unitCache}
                onCacheUpdate={(updates) => setUnitCache((prev) => ({ ...prev, ...updates }))}
              />
            )}
            {currentStep === 'datetime' && selectedAmenity && (
              <AmenityAvailabilityCalendar
                amenity={selectedAmenity}
                selectedDate={selectedDate}
                onSelectDate={(date) => {
                  setSelectedDate(date)
                  setSelectedSlotId(null)
                }}
                selectedSlotId={selectedSlotId}
                onSelectSlot={setSelectedSlotId}
              />
            )}
            {currentStep === 'rules' && selectedAmenity && (
              <RulesStep
                rulesText={selectedAmenity.rules_text ?? ''}
                accepted={rulesAccepted}
                onChange={setRulesAccepted}
              />
            )}
            {currentStep === 'review' && selectedAmenity && (
              <ReviewStep amenity={selectedAmenity} unit={selectedUnit} date={selectedDate ?? ''} />
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
                  disabled={mutation.isPending}
                >
                  {mutation.isPending ? 'Reservando…' : 'Crear reservación'}
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

      {createdReservation && selectedAmenity && (
        <RegisterReservationPaymentSheet
          isOpen={phase === 'payment'}
          onClose={() => finish(createdReservation)}
          reservation={createdReservation}
          amenity={selectedAmenity}
          onRegistered={() => finish(createdReservation)}
        />
      )}
    </>
  )
}

/**
 * Alta de reservación DE AMENIDAD por un admin/directiva EN NOMBRE de cualquier unidad del
 * clúster (a diferencia de `ReserveAmenityWizard`, que es el flujo del residente sobre SUS
 * propias unidades y recibe la amenidad ya elegida). El backend NO restringe qué unidad puede
 * elegir un holder de `update.amenity_reservation` — salvo que siga estando dentro del
 * `target_type`/`target_selector` de la amenidad (422 si no, ver `authorizeAmenityTarget`).
 * Pasos: amenidad → unidad → fecha/horario (calendario compartido) → reglamento (si aplica) →
 * revisión → `POST /amenity_reservation`. Si la reservación creada queda en `PENDING_PAYMENT`
 * (tiene costo y no requiere aprobación), se encadena `RegisterReservationPaymentSheet` — la
 * MISMA que usa el detalle admin — para cobrar en el momento sin round-trip extra a la lista;
 * en cualquier otro desenlace (pendiente de aprobación o confirmada sin costo) navega directo
 * al detalle de la reservación recién creada, donde viven todas las acciones del ciclo de vida.
 */
export function AdminCreateReservationSheet({ isOpen, onClose }: AdminCreateReservationSheetProps) {
  if (!isOpen) return null
  return <AdminCreateReservationSheetBody onClose={onClose} />
}
