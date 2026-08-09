import { ApiError } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { MediaResource } from '@/shared/services/MediaResource'

interface DownloadMediaWithSignedUrlParams {
  mediaId: string
  fileName: string
  errorMessage?: string
}

const DEFAULT_ERROR_MESSAGE = 'No se pudo descargar el archivo'
const NO_FALLBACK_STATUSES = [401, 403]

function shouldUseBlobFallback(error: unknown): boolean {
  if (!(error instanceof ApiError)) {
    return true
  }

  return !NO_FALLBACK_STATUSES.includes(error.status ?? 0)
}

/**
 * Intenta descarga por URL firmada temporal y, si falla, usa fallback por blob.
 */
export async function downloadMediaWithSignedUrl({
  mediaId,
  fileName,
  errorMessage = DEFAULT_ERROR_MESSAGE,
}: DownloadMediaWithSignedUrlParams): Promise<void> {
  try {
    const { download_url } = await MediaResource.getDownloadUrl(mediaId)
    MediaResource.downloadFromSignedUrl(download_url)
    return
  } catch (error) {
    if (!shouldUseBlobFallback(error)) {
      return
    }
  }

  try {
    await MediaResource.download(mediaId, fileName)
  } catch {
    toast.error(errorMessage)
  }
}
