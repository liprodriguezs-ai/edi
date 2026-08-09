import { Badge } from '@/components/ui/badge'
import type { IAmenity } from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { useAmenityStore } from '@/modules/amenity/store/amenityStore'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { displayMoney } from '@/shared/utils/money'
import { ImageOff } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

function AmenityCard({ amenity, onOpen }: { amenity: IAmenity; onOpen: (_a: IAmenity) => void }) {
  const cover = amenity.media?.[0]
  const outOfService = Boolean(amenity.out_of_service)

  return (
    <button
      type='button'
      onClick={() => onOpen(amenity)}
      className='group flex flex-col overflow-hidden rounded-[16px] border border-border bg-card text-left shadow-sm transition-shadow hover:shadow-md'
    >
      <div className='relative aspect-video w-full overflow-hidden bg-[var(--surface-2)]'>
        {cover ? (
          <img
            src={cover.preview_url || cover.original_url}
            alt={amenity.name}
            className={`h-full w-full object-cover ${outOfService ? 'grayscale' : ''}`}
          />
        ) : (
          <div className='flex h-full w-full items-center justify-center text-[var(--ink-4)]'>
            <ImageOff className='size-8' aria-hidden />
          </div>
        )}
        {outOfService && (
          <span className='absolute right-2 top-2 rounded-full bg-black/70 px-2.5 py-1 text-[11px] font-medium text-white'>
            No disponible
          </span>
        )}
      </div>

      <div className='flex flex-1 flex-col gap-2 p-4'>
        <h3 className='text-[15px] font-semibold tracking-[-0.01em] text-foreground'>
          {amenity.name}
        </h3>
        {amenity.description && (
          <p className='line-clamp-2 text-[12.5px] text-[var(--ink-3)]'>{amenity.description}</p>
        )}
        <div className='mt-auto flex flex-wrap items-center gap-1.5 pt-1'>
          <Badge variant='outline'>Cupo {amenity.capacity}</Badge>
          {amenity.has_cost && amenity.cost_amount != null ? (
            <Badge variant='secondary'>{displayMoney(amenity.cost_amount)}</Badge>
          ) : (
            <Badge variant='outline'>Sin costo</Badge>
          )}
          {amenity.requires_deposit && amenity.deposit_amount != null && (
            <Badge variant='outline'>Depósito {displayMoney(amenity.deposit_amount)}</Badge>
          )}
        </div>
      </div>
    </button>
  )
}

/**
 * Catálogo de amenidades para el residente (S14): grid de tarjetas con
 * portada, capacidad y costo/depósito. Las fuera de servicio se muestran
 * atenuadas con la etiqueta "No disponible" en vez de ocultarse — el
 * residente puede seguir viendo el detalle/reglas aunque no pueda reservar.
 */
export const AmenityCatalogPage = () => {
  const navigate = useNavigate()

  const { onPageChanged, onSearch, onReset, paramsSearch } = useBaseList<IAmenity>({
    store: useAmenityStore,
    resource: AmenityResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [{ relation: 'media' }, { relation: 'reservation_mode' }],
    },
  })
  const { entities, pagination, isLoading } = useAmenityStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  const openDetail = (amenity: IAmenity) => navigate(`/amenities/${amenity.id}`)

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Amenidades'
        description='Espacios disponibles para reservar en tu condominio'
      />

      <SearchList
        paramsSearch={paramsSearch}
        onSearch={onSearch}
        onReset={onReset}
        placeholder='Buscar amenidades…'
      />

      {isLoading ? (
        <TableLoadingState />
      ) : entities.length === 0 ? (
        <TableEmptyState
          title='Sin amenidades disponibles'
          description='Tu condominio todavía no tiene amenidades reservables.'
        />
      ) : (
        <>
          <div className='grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3'>
            {entities.map((amenity) => (
              <AmenityCard key={amenity.id} amenity={amenity} onOpen={openDetail} />
            ))}
          </div>
          <Pagination pagination={pagination} onPageChanged={onPageChanged} />
        </>
      )}
    </div>
  )
}
