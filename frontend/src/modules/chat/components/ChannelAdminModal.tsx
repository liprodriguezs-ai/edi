import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  CHANNEL_TARGET_TYPE_LABELS,
  type ChannelTargetType,
  type IChatChannel,
} from '@/modules/chat/schema'
import { ChatChannelResource } from '@/modules/chat/services/ChatResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { useMutation } from '@tanstack/react-query'
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'

interface ChannelAdminModalProps {
  isOpen: boolean
  channels: IChatChannel[]
  onClose: () => void
  onChanged: () => void
}

function targetLabel(targetType?: string | null): string {
  if (!targetType) return 'Todo el condominio'
  return CHANNEL_TARGET_TYPE_LABELS[targetType as ChannelTargetType] ?? targetType
}

/** Administración de canales por tenant (Fase 2): lista + crear / editar / borrar. */
export function ChannelAdminModal({
  isOpen,
  channels,
  onClose,
  onChanged,
}: ChannelAdminModalProps) {
  const navigate = useNavigate()
  const permissions = useMultiplePermissions({
    canCreate: 'create.chat_channel',
    canEdit: 'update.chat_channel',
    canDelete: 'delete.chat_channel',
  })

  const [toDelete, setToDelete] = useState<IChatChannel | null>(null)

  const deleteMutation = useMutation({
    mutationFn: (channel: IChatChannel) => ChatChannelResource.remove(channel.id),
    onSuccess: () => {
      toast.success('Canal eliminado')
      setToDelete(null)
      onChanged()
    },
    onError: (error) =>
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo eliminar'),
  })

  // Alta / edición viven ahora en una página dedicada (más ancha que el modal). Cerramos este
  // modal antes de navegar para no dejar el overlay del Dialog abierto sobre la nueva ruta.
  const goToCreate = useCallback(() => {
    onClose()
    navigate('/chat-channel/new')
  }, [onClose, navigate])

  const goToEdit = useCallback(
    (channel: IChatChannel) => {
      onClose()
      navigate(`/chat-channel/${channel.id}/edit`)
    },
    [onClose, navigate]
  )

  if (!isOpen) return null

  return (
    <>
      <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
        <DialogContent className='max-w-2xl p-0' aria-describedby={undefined}>
          {/* El botón "Nuevo canal" va en su propia barra, NO en el header: shadcn inyecta la X
              de cierre en absolute top-4 right-4 y ahí colisionaría con ella. */}
          <DialogHeader className='px-6 pt-6 pr-14'>
            <DialogTitle>Administrar canales</DialogTitle>
          </DialogHeader>

          {permissions.canCreate && (
            <div className='flex justify-end px-6 pt-3'>
              <Button size='sm' className='rounded-full' onClick={goToCreate}>
                <Plus className='size-4' />
                Nuevo canal
              </Button>
            </div>
          )}

          <div className='max-h-[65vh] overflow-y-auto px-6 py-4'>
            {channels.length === 0 ? (
              <p className='py-8 text-center text-[13px] text-[var(--ink-3)]'>No hay canales.</p>
            ) : (
              <ul className='space-y-2'>
                {channels.map((channel) => (
                  <li
                    key={channel.id}
                    className='flex items-center justify-between gap-3 rounded-[10px] border border-border px-3 py-2.5'
                  >
                    <div className='min-w-0'>
                      <div className='flex items-center gap-2'>
                        <span className='truncate text-[13.5px] font-medium'>{channel.name}</span>
                        {channel.is_system && (
                          <Badge variant='outline' className='gap-1 text-[10px]'>
                            <Lock className='size-3' /> Sistema
                          </Badge>
                        )}
                      </div>
                      <p className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
                        Ve: {targetLabel(channel.target_type)} · Escribe:{' '}
                        {targetLabel(channel.write_target_type)}
                      </p>
                    </div>
                    <div className='flex flex-none items-center gap-1'>
                      {permissions.canEdit && (
                        <Button
                          size='icon'
                          variant='ghost'
                          className='size-8'
                          aria-label={`Editar ${channel.name}`}
                          onClick={() => goToEdit(channel)}
                        >
                          <Pencil className='size-4' />
                        </Button>
                      )}
                      {permissions.canDelete && !channel.is_system && (
                        <Button
                          size='icon'
                          variant='ghost'
                          className='size-8 text-[var(--danger,#b42318)]'
                          aria-label={`Eliminar ${channel.name}`}
                          onClick={() => setToDelete(channel)}
                        >
                          <Trash2 className='size-4' />
                        </Button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        isOpen={toDelete !== null}
        variant='delete'
        entityName={toDelete?.name}
        isLoading={deleteMutation.isPending}
        onConfirm={() => toDelete && deleteMutation.mutate(toDelete)}
        onCancel={() => setToDelete(null)}
      />
    </>
  )
}
