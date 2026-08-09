import { apiClient } from '@/shared/api/apiClient'

const BASE_PATH = '/media'

interface IMediaDownloadMetaResponse {
  download_url: string
  expires_in: number | null
  strategy: 'storage_temporary_url' | 'signed_route'
}

//Resource centralizado para descargas de archivos de Spatie Media Library.
//Usa la ruta genérica GET /media/{mediaId}/download.
export const MediaResource = {
  //Descarga un archivo dado su mediaId y nombre de archivo.
  download: async (mediaId: string, fileName: string): Promise<void> => {
    const { data } = await apiClient.get(`${BASE_PATH}/${mediaId}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = fileName
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  },

  //Descarga un archivo desde cualquier ruta autenticada del backend.
  downloadFromUrl: async (path: string, fileName: string): Promise<void> => {
    const { data } = await apiClient.get(path, { responseType: 'blob' })
    const url = URL.createObjectURL(data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = fileName
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  },

  //Obtiene una URL firmada temporal para descarga directa del archivo.
  getDownloadUrl: async (mediaId: string): Promise<IMediaDownloadMetaResponse> => {
    const { data } = await apiClient.get<IMediaDownloadMetaResponse>(`${BASE_PATH}/${mediaId}/download-url`)
    return data
  },

  //Dispara la descarga del archivo usando URL firmada temporal.
  downloadFromSignedUrl: (downloadUrl: string): void => {
    const a = document.createElement('a')
    a.href = downloadUrl
    a.rel = 'noopener noreferrer'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
  },

  //Obtiene el blob de un archivo para pre-visualización (ej. abrir PDF en nueva pestaña).
  preview: async (mediaId: string): Promise<string> => {
    const { data } = await apiClient.get(`${BASE_PATH}/${mediaId}/download`, { responseType: 'blob' })
    return URL.createObjectURL(data as Blob)
  },
}
