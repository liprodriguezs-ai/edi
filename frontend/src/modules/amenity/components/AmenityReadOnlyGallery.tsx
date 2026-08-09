import type { IMedia } from '@/shared/interfaces/Entity'
import { ImageOff } from 'lucide-react'

/**
 * Galería de fotos SOLO LECTURA para la vista de exploración del residente
 * (S14) — a diferencia de `AmenityGallery` (admin), no permite subir/borrar.
 */
export function AmenityReadOnlyGallery({
  media,
  amenityName,
}: {
  media: IMedia[]
  amenityName: string
}) {
  // Sin fotos: un marcador COMPACTO (no un hero `aspect-video` que ocuparía
  // ~500px en desktop y empujaría el contenido real bajo el pliegue).
  if (media.length === 0) {
    return (
      <div className='flex items-center justify-center gap-2 rounded-[14px] border border-dashed border-border bg-[var(--surface-2)] px-4 py-6 text-[12.5px] text-[var(--ink-4)]'>
        <ImageOff className='size-4' aria-hidden />
        Sin fotos
      </div>
    )
  }

  const [cover, ...rest] = media

  return (
    <div className='grid grid-cols-4 gap-2'>
      <img
        src={cover.preview_url || cover.original_url}
        alt={cover.name || amenityName}
        className='col-span-4 aspect-video w-full rounded-[14px] border border-border object-cover sm:col-span-3'
      />
      {rest.length > 0 && (
        <div className='col-span-4 grid grid-cols-4 gap-2 sm:col-span-1 sm:grid-cols-1'>
          {rest.slice(0, 3).map((item) => (
            <img
              key={item.id}
              src={item.preview_url || item.original_url}
              alt={item.name || amenityName}
              className='aspect-square w-full rounded-[10px] border border-border object-cover'
            />
          ))}
        </div>
      )}
    </div>
  )
}
