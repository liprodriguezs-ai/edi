/**
 * Comprime una imagen a formato WebP usando la Canvas API del navegador.
 * Sin dependencias externas — usa sólo APIs nativas del browser.
 *
 * @param file - Archivo de imagen original (cualquier formato soportado por el browser)
 * @param options.maxDimension - Dimensión máxima del lado más largo (default 1600px)
 * @param options.quality - Calidad WebP entre 0 y 1 (default 0.8)
 * @returns Promesa que resuelve con el archivo WebP comprimido
 */

export interface CompressOptions {
  maxDimension?: number
  quality?: number
}

export async function compressImageToWebP(
  file: File,
  options: CompressOptions = {}
): Promise<File> {
  const { maxDimension = 1600, quality = 0.8 } = options

  return new Promise<File>((resolve, reject) => {
    const img = new Image()
    const objectUrl = URL.createObjectURL(file)

    img.onload = () => {
      URL.revokeObjectURL(objectUrl)

      const { width, height } = img
      const longestSide = Math.max(width, height)
      const scale = longestSide > maxDimension ? maxDimension / longestSide : 1
      const outWidth = Math.round(width * scale)
      const outHeight = Math.round(height * scale)

      const canvas = document.createElement('canvas')
      canvas.width = outWidth
      canvas.height = outHeight

      const ctx = canvas.getContext('2d')
      if (!ctx) {
        reject(new Error('No se pudo obtener el contexto 2D del canvas'))
        return
      }

      ctx.drawImage(img, 0, 0, outWidth, outHeight)

      canvas.toBlob(
        (blob) => {
          if (!blob) {
            reject(new Error('No se pudo comprimir la imagen'))
            return
          }
          // Reemplaza la extensión original por .webp
          const baseName = file.name.replace(/\.[^.]+$/, '')
          const outFile = new File([blob], `${baseName}.webp`, {
            type: 'image/webp',
          })
          resolve(outFile)
        },
        'image/webp',
        quality
      )
    }

    img.onerror = () => {
      URL.revokeObjectURL(objectUrl)
      reject(new Error('No se pudo cargar la imagen para comprimir'))
    }

    img.src = objectUrl
  })
}

/**
 * Valida si un archivo es una imagen admitida por el backend.
 * Tipos permitidos: jpeg, png, webp, gif.
 */
export function isValidImageFile(file: File): boolean {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
  return allowed.includes(file.type)
}

/**
 * Valida el tamaño del archivo (máximo 5 MB).
 */
export function isValidImageSize(file: File, maxMB = 5): boolean {
  return file.size <= maxMB * 1024 * 1024
}

/**
 * Extensiones de archivo admitidas para adjuntos genéricos (imágenes + documentos),
 * espejo de `App\Support\MediaAttachmentPolicy::EXTENSIONS` del backend.
 */
const ALLOWED_ATTACHMENT_EXTENSIONS = [
  'pdf',
  'jpg',
  'jpeg',
  'png',
  'webp',
  'gif',
  'mp4',
  'mp3',
  'doc',
  'docx',
  'xls',
  'xlsx',
]

/**
 * Valida si un archivo tiene una extensión admitida para adjuntos de chat
 * (imágenes + documentos), por nombre de archivo — mismo criterio que el
 * backend, que valida por extensión (`mimes:`), no solo por `file.type`.
 */
export function isAllowedAttachment(file: File): boolean {
  const extension = file.name.split('.').pop()?.toLowerCase()
  if (!extension) return false
  return ALLOWED_ATTACHMENT_EXTENSIONS.includes(extension)
}

/**
 * Valida el tamaño de un adjunto genérico (máximo 10 MB, espejo del backend).
 */
export function isValidAttachmentSize(file: File, maxMB = 10): boolean {
  return file.size <= maxMB * 1024 * 1024
}
