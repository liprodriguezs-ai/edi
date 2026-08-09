import { Button } from '@/components/ui/button'
import { AmenityForm } from '@/modules/amenity/components/AmenityForm'
import type { IAmenity } from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { ArrowLeft } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'

/**
 * Página de alta/edición de una amenidad. En alta muestra solo el formulario;
 * al crear, navega a la edición de la amenidad recién creada para que el admin
 * configure de inmediato horarios/bloqueos/galería (reemplaza cualquier Sheet).
 */
export const AmenityFormPage = () => {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isEdit = Boolean(id)

  const {
    entity: amenity,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IAmenity>({
    queryKey: ['amenity', 'detail', id],
    queryFn: () =>
      AmenityResource.show(id as string, {
        include: 'reservation_mode,bank_account,slots,blackouts,media',
      }),
    enabled: isEdit,
  })

  const goToList = () => navigate('/amenity')

  return (
    <div className='mx-auto w-full max-w-4xl space-y-6'>
      <div className='flex items-center gap-3'>
        <Button
          variant='ghost'
          size='icon'
          className='size-8'
          onClick={goToList}
          aria-label='Volver a amenidades'
        >
          <ArrowLeft className='size-4' />
        </Button>
        <PageHeader
          title={isEdit ? 'Editar amenidad' : 'Nueva amenidad'}
          description={
            isEdit
              ? 'Edita la configuración, horarios, bloqueos y galería'
              : 'Configura una nueva amenidad reservable del condominio'
          }
        />
      </div>

      {!isEdit ? (
        <AmenityForm
          mode={CrudMode.CREATE}
          onSaved={(created) => navigate(`/amenity/${created.id}/edit`)}
          onCancel={goToList}
        />
      ) : isLoading ? (
        <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando amenidad…</p>
      ) : isError || !amenity ? (
        <EntityNotFound
          variant={notFound ? 'notFound' : 'error'}
          title={notFound ? 'Amenidad no encontrada' : undefined}
          backTo='/amenity'
          backLabel='Volver a amenidades'
          onRetry={notFound ? undefined : refetch}
        />
      ) : (
        <AmenityForm
          mode={CrudMode.EDIT}
          amenity={amenity}
          onSaved={() => undefined}
          onCancel={goToList}
        />
      )}
    </div>
  )
}
