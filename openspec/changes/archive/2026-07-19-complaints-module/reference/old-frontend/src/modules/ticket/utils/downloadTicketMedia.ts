import type { IMedia } from '@/shared/interfaces/Entity'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'

export async function downloadTicketMedia(media: IMedia): Promise<void> {
  await downloadMediaWithSignedUrl({
    mediaId: media.id,
    fileName: media.file_name,
    errorMessage: 'No se pudo descargar el archivo adjunto',
  })
}
