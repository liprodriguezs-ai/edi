import { Button } from '@/components/ui/button'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { toast } from '@/shared/hooks/useToast'
import type { IMedia } from '@/shared/interfaces/Entity'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Download, Paperclip, X } from 'lucide-react'
import { useRef, useState } from 'react'

const MAX_DOCUMENTS = 5
const MAX_FILE_BYTES = 10 * 1024 * 1024

interface ProjectDocumentsProps {
  projectId: string
  media: IMedia[]
  /** La mesa (update.community_project) puede subir/eliminar; el resto solo descarga. */
  canManage: boolean
}

/**
 * Documentos del proyecto (§11): consultables por cualquiera que vea el proyecto
 * (descarga vía URL firmada). La mesa además gestiona la colección — hasta 5 archivos
 * (PDF/imagen/Word), 10 MB c/u; el 6º lo rechaza el backend (no hay rotación silenciosa).
 * Es el lugar de administración post-creación (no hay formulario de edición del proyecto).
 */
export function ProjectDocuments({ projectId, media, canManage }: ProjectDocumentsProps) {
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [downloadingId, setDownloadingId] = useState<string | null>(null)

  const documents = media.filter((item) => item.collection_name === 'documents')

  const deleteMutation = useMutation({
    mutationFn: (mediaId: string) => CommunityProjectResource.deleteMedia(projectId, mediaId),
  })

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ['project', 'detail', projectId] })

  const busy = uploading || deleteMutation.isPending
  const atLimit = documents.length >= MAX_DOCUMENTS

  const download = async (item: IMedia) => {
    setDownloadingId(item.id)
    try {
      await downloadMediaWithSignedUrl({
        mediaId: item.id,
        fileName: item.file_name,
        errorMessage: 'No se pudo descargar el documento',
      })
    } finally {
      setDownloadingId(null)
    }
  }

  const handleAdd = async (picked: FileList | null) => {
    if (!picked || picked.length === 0) return
    const incoming = Array.from(picked)
    const available = MAX_DOCUMENTS - documents.length
    if (available <= 0) {
      toast.error(`Máximo ${MAX_DOCUMENTS} documentos por proyecto`)
      return
    }
    const tooBig = incoming.find((file) => file.size > MAX_FILE_BYTES)
    if (tooBig) {
      toast.error(`"${tooBig.name}" supera el límite de 10 MB`)
      return
    }
    const toUpload = incoming.slice(0, available)
    if (incoming.length > available) {
      toast.error(`Solo caben ${available} documento(s) más — se subirán los primeros ${available}`)
    }
    setUploading(true)
    let failed = 0
    for (const file of toUpload) {
      try {
        await CommunityProjectResource.uploadMedia(projectId, file)
      } catch {
        failed += 1
      }
    }
    setUploading(false)
    await refresh()
    if (failed > 0) {
      toast.error(
        `${failed} ${failed === 1 ? 'documento no se subió' : 'documentos no se subieron'}`
      )
    } else {
      toast.success(toUpload.length === 1 ? 'Documento agregado' : 'Documentos agregados')
    }
  }

  const handleRemove = async (item: IMedia) => {
    try {
      await deleteMutation.mutateAsync(item.id)
      await refresh()
      toast.success('Documento eliminado')
    } catch {
      toast.error(`No se pudo eliminar ${item.name || item.file_name}`)
    }
  }

  if (documents.length === 0 && !canManage) {
    return null
  }

  return (
    <section className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
      <div className='mb-3 flex items-center justify-between gap-3'>
        <h3 className='text-sm font-semibold'>Documentos</h3>
        {canManage && (
          <>
            <input
              ref={fileInputRef}
              type='file'
              multiple
              accept='image/*,application/pdf,.doc,.docx'
              className='hidden'
              onChange={(e) => {
                void handleAdd(e.target.files)
                e.target.value = ''
              }}
              disabled={busy}
            />
            <Button
              type='button'
              variant='outline'
              size='sm'
              className='rounded-full'
              onClick={() => fileInputRef.current?.click()}
              disabled={busy || atLimit}
            >
              <Paperclip className='size-4' />
              {uploading ? 'Subiendo…' : 'Agregar documento'}
            </Button>
          </>
        )}
      </div>

      {documents.length === 0 ? (
        <p className='text-[12.5px] text-[var(--ink-4)]'>Sin documentos.</p>
      ) : (
        <ul className='space-y-1.5'>
          {documents.map((item) => (
            <li
              key={item.id}
              className='flex items-center justify-between gap-2 rounded-[10px] border border-border bg-[var(--surface-2)] px-3 py-1.5 text-[12.5px]'
            >
              <button
                type='button'
                className='flex min-w-0 items-center gap-2 text-left hover:text-[var(--green-deep)] disabled:opacity-40'
                onClick={() => void download(item)}
                disabled={downloadingId === item.id}
                aria-label={`Descargar ${item.name || item.file_name}`}
              >
                <Download className='size-4 flex-none' />
                <span className='truncate'>{item.name || item.file_name}</span>
              </button>
              {canManage && (
                <button
                  type='button'
                  className='flex-none text-[var(--ink-4)] hover:text-destructive disabled:opacity-40'
                  onClick={() => void handleRemove(item)}
                  disabled={busy}
                  aria-label={`Quitar ${item.name || item.file_name}`}
                >
                  <X className='size-4' />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {canManage && (
        <p className='mt-2 text-[11.5px] text-[var(--ink-4)]'>
          Hasta {MAX_DOCUMENTS} documentos (PDF, imagen o Word), máx. 10 MB cada uno.
        </p>
      )}
    </section>
  )
}
