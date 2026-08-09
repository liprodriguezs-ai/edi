import { Button } from '@/components/ui/button'
import { AmenityAvailabilityCalendar } from '@/modules/amenity/components/AmenityAvailabilityCalendar'
import { AmenityReadOnlyGallery } from '@/modules/amenity/components/AmenityReadOnlyGallery'
import type { IAmenity } from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { ReserveAmenityWizard } from '@/modules/reservation/components/ReserveAmenityWizard'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { displayMoney } from '@/shared/utils/money'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

const DETAIL_INCLUDE = ['media', 'slots', 'blackouts', 'reservation_mode', 'bank_account'].join(',')

interface DetailFieldProps {
  label: string
  children: React.ReactNode
}

function DetailField({ label, children }: DetailFieldProps) {
  return (
    <div>
      <dt className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        {label}
      </dt>
      <dd className='mt-1 text-[14px] text-foreground'>{children}</dd>
    </div>
  )
}

function AmenitySummary({ amenity }: { amenity: IAmenity }) {
  return (
    <section className='rounded-[16px] border border-border bg-card p-5 shadow-sm'>
      <dl className='grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3'>
        <DetailField label='Cupo simultáneo'>{amenity.capacity}</DetailField>
        <DetailField label='Costo'>
          {amenity.has_cost && amenity.cost_amount != null
            ? displayMoney(amenity.cost_amount)
            : 'Sin costo'}
        </DetailField>
        {amenity.requires_deposit && amenity.deposit_amount != null && (
          <DetailField label='Depósito requerido'>
            {displayMoney(amenity.deposit_amount)}
          </DetailField>
        )}
        {amenity.requires_approval && (
          <DetailField label='Aprobación'>Requiere aprobación del administrador</DetailField>
        )}
      </dl>

      {amenity.restrictions && (
        <div className='mt-4 border-t border-[var(--line-2)] pt-4'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Restricciones
          </div>
          <p className='mt-1 whitespace-pre-line text-[13.5px] text-foreground'>
            {amenity.restrictions}
          </p>
        </div>
      )}

      {amenity.rules_text && (
        <div className='mt-4 border-t border-[var(--line-2)] pt-4'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Reglamento
          </div>
          <p className='mt-1 whitespace-pre-line text-[13.5px] text-foreground'>
            {amenity.rules_text}
          </p>
        </div>
      )}
    </section>
  )
}

/**
 * Detalle de amenidad para el residente (S14): galería de solo lectura,
 * resumen (capacidad/costo/depósito/restricciones/reglamento) y el calendario
 * de disponibilidad compartido con el wizard de reservación. El botón
 * "Reservar" abre el wizard (S15) prefijado con la fecha/horario ya elegidos
 * en la vista previa, sin obligar al residente a repetir la selección.
 */
export const AmenityBrowseDetailPage = () => {
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const [selectedDate, setSelectedDate] = useState<string | null>(null)
  const [selectedSlotId, setSelectedSlotId] = useState<string | null>(null)
  const [wizardOpen, setWizardOpen] = useState(false)

  const {
    entity: amenity,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IAmenity>({
    queryKey: ['amenity', 'browse-detail', id],
    queryFn: () => AmenityResource.show(id, { include: DETAIL_INCLUDE }),
    enabled: Boolean(id),
  })

  if (isLoading) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando amenidad…</p>
  }

  if (isError || !amenity) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Amenidad no encontrada' : undefined}
        description={
          notFound ? 'La amenidad no existe o no está disponible en tu condominio.' : undefined
        }
        backTo='/amenities'
        backLabel='Volver a amenidades'
        onRetry={notFound ? undefined : refetch}
      />
    )
  }

  return (
    <div className='space-y-5'>
      <Button variant='ghost' className='-ml-2 gap-1.5' onClick={() => navigate('/amenities')}>
        <ArrowLeft className='size-4' />
        Volver a amenidades
      </Button>

      <div className='flex flex-wrap items-start justify-between gap-4'>
        <h1 className='text-[26px] font-semibold tracking-[-0.02em]'>{amenity.name}</h1>
        {!amenity.out_of_service && (
          <Button className='rounded-full' onClick={() => setWizardOpen(true)}>
            Reservar
          </Button>
        )}
      </div>

      <AmenityReadOnlyGallery media={amenity.media ?? []} amenityName={amenity.name} />

      {amenity.description && (
        <p className='text-[13.5px] text-[var(--ink-3)]'>{amenity.description}</p>
      )}

      <AmenitySummary amenity={amenity} />

      <section className='space-y-3'>
        <h2 className='text-[15px] font-semibold tracking-[-0.01em]'>Disponibilidad</h2>
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
      </section>

      <ReserveAmenityWizard
        isOpen={wizardOpen}
        onClose={() => setWizardOpen(false)}
        amenity={amenity}
        initialDate={selectedDate}
        initialSlotId={selectedSlotId}
      />
    </div>
  )
}
