import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { UserResource } from '@/modules/user/services/UserResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { useSession } from '@/shared/auth/useSession'
import { toast } from '@/shared/hooks/useToast'
import { displayInitials } from '@/shared/utils/displayInitials'
import { useMutation } from '@tanstack/react-query'
import { Trash2, Upload } from 'lucide-react'
import { useRef, useState, type ChangeEvent } from 'react'

interface ProfilePhotoModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * Foto de perfil del usuario autenticado: sube (POST /user/uploadProfileImage,
 * single-file: reemplaza la anterior) o elimina (POST /user/deleteProfileImage).
 * Tras cambiar, recarga el perfil de sesión para refrescar el avatar.
 */
export function ProfilePhotoModal({ isOpen, onClose }: ProfilePhotoModalProps) {
  const { user, loadProfile } = useSession()
  const fileRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)

  // Reset transient state on the open transition using React's "adjust state on
  // prop change" pattern (during render, NOT in an effect) — see ScanQrModal.
  const [wasOpen, setWasOpen] = useState(isOpen)
  if (isOpen !== wasOpen) {
    setWasOpen(isOpen)
    if (isOpen) {
      setFile(null)
      setPreview(null)
    }
  }

  const onPick = (event: ChangeEvent<HTMLInputElement>) => {
    const picked = event.target.files?.[0]
    if (!picked) return
    if (picked.size > 5 * 1024 * 1024) {
      toast.error('La imagen no debe superar 5MB')
      return
    }
    setFile(picked)
    setPreview(URL.createObjectURL(picked))
  }

  const uploadMutation = useMutation({
    mutationFn: () => UserResource.uploadProfileImage(file as File),
    onSuccess: async () => {
      await loadProfile()
      toast.success('Foto de perfil actualizada')
      onClose()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo actualizar la foto'
      ),
  })

  const deleteMutation = useMutation({
    mutationFn: () => UserResource.deleteProfileImage(),
    onSuccess: async () => {
      await loadProfile()
      toast.success('Foto de perfil eliminada')
      onClose()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo eliminar la foto'
      ),
  })

  const isLoading = uploadMutation.isPending || deleteMutation.isPending
  const currentUrl = preview ?? user?.profile_image_url ?? null

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='max-w-sm' aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>Foto de perfil</DialogTitle>
        </DialogHeader>

        <div className='flex flex-col items-center gap-4 py-2'>
          <div className='grid size-28 place-items-center overflow-hidden rounded-full border border-border bg-[var(--green-bg)]'>
            {currentUrl ? (
              <img src={currentUrl} alt='Foto de perfil' className='size-full object-cover' />
            ) : (
              <span className='text-2xl font-semibold text-[var(--green)]'>
                {displayInitials(user?.name ?? '')}
              </span>
            )}
          </div>

          <input
            ref={fileRef}
            type='file'
            accept='image/*'
            className='hidden'
            onChange={onPick}
            disabled={isLoading}
          />
          <Button
            variant='outline'
            className='rounded-full'
            onClick={() => fileRef.current?.click()}
            disabled={isLoading}
          >
            <Upload className='size-4' />
            Elegir imagen
          </Button>
          {file && <p className='text-[12.5px] text-[var(--ink-3)]'>{file.name}</p>}
        </div>

        <DialogFooter className='gap-2'>
          {user?.profile_image_url && (
            <Button
              variant='outline'
              className='text-destructive hover:text-destructive'
              onClick={() => deleteMutation.mutate()}
              disabled={isLoading}
            >
              <Trash2 className='size-4' />
              Eliminar
            </Button>
          )}
          <Button onClick={() => uploadMutation.mutate()} disabled={!file || isLoading}>
            {uploadMutation.isPending ? 'Guardando…' : 'Guardar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
