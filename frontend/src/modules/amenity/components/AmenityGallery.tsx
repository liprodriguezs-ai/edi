import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import type { IMedia } from '@/shared/interfaces/Entity'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, X } from 'lucide-react'
import { useRef, useState } from 'react'

const MAX_IMAGES = 20
const MAX_FILE_BYTES = 10 * 1024 * 1024

/**
 * Galería de fotos de una amenidad — operaciones INMEDIATAS contra la amenidad
 * ya persistida (Patrón B: endpoint de media dedicado, igual que Announcement).
 * Solo aplica en edición: una amenidad nueva no tiene `id` para adjuntar fotos.
 */
export function AmenityGallery({
  amenityId,
  media,
  disabled,
}: {
  amenityId: string
  media: IMedia[]
  disabled: boolean
}) {
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: (mediaId: string) => AmenityResource.deleteMedia(amenityId, mediaId),
  })

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ['amenity', 'detail', amenityId] })

  const busy = disabled || uploading || deleteMutation.isPending
  const atLimit = media.length >= MAX_IMAGES

  const handleAdd = async (picked: FileList | null) => {
    if (!picked || picked.length === 0) return
    const incoming = Array.from(picked)
    const available = MAX_IMAGES - media.length
    if (available <= 0) {
      toast.error(`Máximo ${MAX_IMAGES} fotos por amenidad`)
      return
    }
    const tooBig = incoming.find((f) => f.size > MAX_FILE_BYTES)
    if (tooBig) {
      toast.error(`"${tooBig.name}" supera el límite de 10 MB`)
      return
    }
    const toUpload = incoming.slice(0, available)
    if (incoming.length > available) {
      toast.error(`Solo caben ${available} foto(s) más — se subirán las primeras ${available}`)
    }
    setUploading(true)
    let failed = 0
    for (const file of toUpload) {
      try {
        await AmenityResource.uploadMedia(amenityId, file)
      } catch {
        failed += 1
      }
    }
    setUploading(false)
    await refresh()
    if (failed > 0) {
      toast.error(`${failed} ${failed === 1 ? 'foto no se subió' : 'fotos no se subieron'}`)
    } else {
      toast.success(toUpload.length === 1 ? 'Foto agregada' : 'Fotos agregadas')
    }
  }

  const handleRemove = async (item: IMedia) => {
    try {
      await deleteMutation.mutateAsync(item.id)
      await refresh()
      toast.success('Foto eliminada')
    } catch {
      toast.error('No se pudo eliminar la foto')
    }
  }

  return (
    <Field>
      <FieldLabel>Galería de fotos</FieldLabel>
      <FieldDescription>Hasta {MAX_IMAGES} imágenes, máx. 10 MB cada una.</FieldDescription>
      <input
        ref={fileInputRef}
        type='file'
        multiple
        accept='image/jpeg,image/png,image/webp,image/gif'
        className='hidden'
        onChange={(e) => {
          void handleAdd(e.target.files)
          e.target.value = ''
        }}
        disabled={busy}
      />
      <div className='flex flex-col gap-3'>
        <Button
          type='button'
          variant='outline'
          size='sm'
          className='w-fit'
          onClick={() => fileInputRef.current?.click()}
          disabled={busy || atLimit}
        >
          <ImagePlus className='size-4' />
          {uploading ? 'Subiendo…' : 'Agregar foto'}
        </Button>
        {media.length > 0 ? (
          <div className='grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4'>
            {media.map((item) => (
              <div
                key={item.id}
                className='group relative aspect-square overflow-hidden rounded-[10px] border border-border bg-[var(--surface-2)]'
              >
                <img
                  src={item.preview_url || item.original_url}
                  alt={item.name || item.file_name}
                  className='h-full w-full object-cover'
                />
                <button
                  type='button'
                  className='absolute right-1.5 top-1.5 flex size-6 items-center justify-center rounded-full bg-black/60 text-white hover:bg-destructive disabled:opacity-40'
                  onClick={() => void handleRemove(item)}
                  disabled={busy}
                  aria-label={`Quitar ${item.name || item.file_name}`}
                >
                  <X className='size-3.5' />
                </button>
              </div>
            ))}
          </div>
        ) : (
          <p className='text-[12px] text-[var(--ink-4)]'>Sin fotos todavía.</p>
        )}
      </div>
    </Field>
  )
}
