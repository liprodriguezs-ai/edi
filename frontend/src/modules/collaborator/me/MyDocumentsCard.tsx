import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import type { ICollaborator } from '@/modules/collaborator/schema'
import { Skeleton } from 'boneyard-js/react'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { CheckCircle2, Circle, Download, FileStack, Loader2 } from 'lucide-react'
import { memo, useCallback, useState } from 'react'

type ChecklistItem = NonNullable<ICollaborator['document_checklist']>[number]

interface MyDocumentsCardProps {
  collaborator: ICollaborator | null
  isLoading: boolean
}

function DocumentRow({ item }: { item: ChecklistItem }) {
  const [isDownloading, setIsDownloading] = useState(false)

  const handleDownload = useCallback(async () => {
    if (!item.media_id) {
      return
    }
    setIsDownloading(true)
    try {
      await downloadMediaWithSignedUrl({
        mediaId: item.media_id,
        fileName: item.file_name ?? item.name,
        errorMessage: 'No se pudo descargar el documento',
      })
    } finally {
      setIsDownloading(false)
    }
  }, [item.file_name, item.media_id, item.name])

  return (
    <li className='flex items-center gap-3 px-4 py-3 sm:px-6'>
      <div className='shrink-0'>
        {item.uploaded ? (
          <CheckCircle2 className='size-5 text-primary' aria-hidden />
        ) : (
          <Circle className='size-5 text-muted-foreground/30' aria-hidden />
        )}
      </div>
      <div className='min-w-0 flex-1'>
        <p className='truncate text-sm font-medium text-foreground'>{item.name}</p>
        <div className='flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground'>
          {item.uploaded && item.updated_at && (
            <span>Cargado: {displayFormatDate(item.updated_at, 'mediumDate')}</span>
          )}
          {!item.uploaded && <span>No cargado</span>}
          {item.expiration_date && (
            <span>&middot; Vence: {displayFormatDate(item.expiration_date, 'mediumDate')}</span>
          )}
        </div>
      </div>
      {item.required && (
        <span className='hidden shrink-0 rounded-full bg-destructive/10 px-2 py-0.5 text-[11px] font-medium text-destructive sm:inline'>
          Obligatorio
        </span>
      )}
      <Badge variant={item.uploaded ? 'default' : 'secondary'} className='shrink-0 text-xs'>
        {item.uploaded ? 'Cargado' : 'Pendiente'}
      </Badge>
      {item.media_id && (
        <button
          type='button'
          onClick={() => void handleDownload()}
          disabled={isDownloading}
          className='inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50'
          aria-label={`Descargar ${item.name}`}
        >
          {isDownloading ? (
            <Loader2 className='size-4 animate-spin' aria-hidden />
          ) : (
            <Download className='size-4' />
          )}
        </button>
      )}
    </li>
  )
}

function MyDocumentsCardInner({ collaborator, isLoading }: MyDocumentsCardProps) {
  const list = collaborator?.document_checklist ?? []
  const uploadedCount = list.filter((i) => i.uploaded).length
  const sorted = [...list].sort((a, b) => {
    if (a.required !== b.required) return a.required ? -1 : 1
    return a.uploaded === b.uploaded ? 0 : a.uploaded ? 1 : -1
  })

  return (
    <Skeleton name='my-documents-card' loading={isLoading}>
      <Card>
        <CardHeader className='pb-3'>
          <CardTitle className='flex items-center gap-2 text-base'>
            <CheckCircle2 className='size-5 text-primary' />
            Documentos ({uploadedCount} de {list.length})
          </CardTitle>
        </CardHeader>
        <CardContent className='p-0'>
          {list.length === 0 ? (
            <Empty className='border-0 py-8'>
              <EmptyHeader>
                <EmptyMedia variant='icon'>
                  <FileStack className='size-6' />
                </EmptyMedia>
                <EmptyTitle>No hay documentos</EmptyTitle>
                <EmptyDescription>
                  Aún no tienes documentos asignados en tu expediente. Si deberías tener alguno,
                  contacta a tu área de RH.
                </EmptyDescription>
              </EmptyHeader>
            </Empty>
          ) : (
            <ul className='divide-y divide-border' role='list'>
              {sorted.map((item) => (
                <DocumentRow key={item.document_id} item={item} />
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </Skeleton>
  )
}

export const MyDocumentsCard = memo(MyDocumentsCardInner)
