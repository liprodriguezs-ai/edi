import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { AnnouncementForm } from '@/modules/announcement/components/AnnouncementForm'
import { AnnouncementReadStats } from '@/modules/announcement/components/AnnouncementReadStats'
import type { IAnnouncement } from '@/modules/announcement/schema'
import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

type EditorTab = 'content' | 'reads'

/**
 * Página de alta/edición de un aviso. En alta muestra solo el formulario; en edición usa
 * tabs: "Contenido" (el formulario) y "Lecturas" (estadísticas de lectura, cargadas LAZY al
 * abrir el tab). Reemplaza al Sheet lateral anterior.
 */
export const AnnouncementEditorPage = () => {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isEdit = Boolean(id)
  const [tab, setTab] = useState<EditorTab>('content')

  const {
    entity: announcement,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IAnnouncement>({
    queryKey: ['announcement', 'detail', id],
    // Incluye `media` para que el gestor de adjuntos del formulario (modo edición) liste los
    // adjuntos existentes; type/priority/status van para compartir la caché con la vista.
    queryFn: () =>
      AnnouncementResource.show(id as string, { include: 'type,priority,status,media' }),
    enabled: isEdit,
  })

  const goToList = () => navigate('/announcement')

  return (
    <div className='mx-auto w-full max-w-5xl space-y-6'>
      <div className='flex items-center gap-3'>
        <Button
          variant='ghost'
          size='icon'
          className='size-8'
          onClick={goToList}
          aria-label='Volver a avisos'
        >
          <ArrowLeft className='size-4' />
        </Button>
        <PageHeader
          title={isEdit ? 'Editar aviso' : 'Nuevo aviso'}
          description={
            isEdit
              ? 'Edita el comunicado y consulta quién lo ha leído'
              : 'Redacta un comunicado para los residentes'
          }
        />
      </div>

      {!isEdit ? (
        <AnnouncementForm mode={CrudMode.CREATE} onSaved={goToList} onCancel={goToList} />
      ) : (
        <Tabs value={tab} onValueChange={(value) => setTab(value as EditorTab)}>
          <TabsList>
            <TabsTrigger value='content'>Contenido</TabsTrigger>
            <TabsTrigger value='reads'>Lecturas</TabsTrigger>
          </TabsList>

          {/* forceMount mantiene el formulario montado (oculto) al cambiar de tab, para no
              perder lo que el usuario escribió si abre "Lecturas" y vuelve. */}
          <TabsContent value='content' forceMount className='pt-4 data-[state=inactive]:hidden'>
            {isLoading ? (
              <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando aviso…</p>
            ) : isError || !announcement ? (
              <EntityNotFound
                variant={notFound ? 'notFound' : 'error'}
                title={notFound ? 'Aviso no encontrado' : undefined}
                backTo='/announcement'
                backLabel='Volver a avisos'
                onRetry={notFound ? undefined : refetch}
              />
            ) : (
              <AnnouncementForm
                mode={CrudMode.EDIT}
                announcement={announcement}
                onSaved={goToList}
                onCancel={goToList}
              />
            )}
          </TabsContent>

          <TabsContent value='reads' className='pt-4'>
            {/* Radix desmonta el contenido del tab inactivo; además gateamos la query con
                `enabled` para no pegarle al endpoint hasta abrir "Lecturas" (§2.12). */}
            {id && <AnnouncementReadStats announcementId={id} enabled={tab === 'reads'} />}
          </TabsContent>
        </Tabs>
      )}
    </div>
  )
}
