/**
 * Compartir un código QR como IMAGEN usando el Web Share API nivel 2.
 *
 * El API de archivos (`navigator.share({ files })`) solo existe en contexto
 * seguro (HTTPS o localhost) y no todos los navegadores lo soportan — Firefox
 * no lo tiene y en escritorio el soporte es parcial. Por eso la función
 * degrada en cascada: imagen -> texto -> portapapeles, y le informa al llamador
 * qué camino tomó para que muestre el feedback correcto.
 */

export type ShareQrResult =
  /** Se compartió la imagen PNG del QR (más el texto). */
  | 'shared-image'
  /** El navegador no pudo con la imagen; se compartió solo el texto. */
  | 'shared-text'
  /** Sin Web Share API; se copió el texto al portapapeles. */
  | 'copied'
  /** El usuario cerró la hoja de compartir. */
  | 'cancelled'
  /** Ni compartir ni portapapeles disponibles. */
  | 'unsupported'

export interface ShareQrOptions {
  /** Canvas con el QR ya renderizado. Si es null se comparte solo texto. */
  canvas: HTMLCanvasElement | null
  title: string
  /** Texto de respaldo; también es lo que se copia si no hay Web Share API. */
  text: string
  /** Nombre del archivo PNG que verá quien recibe (incluye la extensión). */
  fileName: string
}

/** El usuario cerró la hoja nativa de compartir: no es un error a reportar. */
function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError'
}

/** Convierte el canvas a un File PNG. Devuelve null si el navegador no puede. */
async function canvasToPngFile(canvas: HTMLCanvasElement, fileName: string): Promise<File | null> {
  if (typeof canvas.toBlob !== 'function' || typeof File === 'undefined') {
    return null
  }

  const blob = await new Promise<Blob | null>((resolve) => {
    canvas.toBlob(resolve, 'image/png')
  })

  return blob ? new File([blob], fileName, { type: 'image/png' }) : null
}

/** Intenta compartir el QR como imagen. null = este navegador no puede. */
async function shareAsImage(options: ShareQrOptions): Promise<ShareQrResult | null> {
  const { canvas, title, text, fileName } = options

  if (!canvas || typeof navigator.canShare !== 'function') {
    return null
  }

  const file = await canvasToPngFile(canvas, fileName)
  if (!file || !navigator.canShare({ files: [file] })) {
    return null
  }

  try {
    await navigator.share({ title, text, files: [file] })
    return 'shared-image'
  } catch (error) {
    // Un fallo que no sea cancelación cae al share de solo texto.
    return isAbortError(error) ? 'cancelled' : null
  }
}

/**
 * Comparte el QR por el mejor canal disponible.
 *
 * Orden: imagen PNG + texto -> solo texto -> portapapeles.
 */
export async function shareQr(options: ShareQrOptions): Promise<ShareQrResult> {
  const { title, text } = options

  if (typeof navigator === 'undefined') {
    return 'unsupported'
  }

  if (typeof navigator.share === 'function') {
    const imageResult = await shareAsImage(options)
    if (imageResult) {
      return imageResult
    }

    try {
      await navigator.share({ title, text })
      return 'shared-text'
    } catch (error) {
      if (isAbortError(error)) {
        return 'cancelled'
      }
    }
  }

  if (navigator.clipboard) {
    await navigator.clipboard.writeText(text)
    return 'copied'
  }

  return 'unsupported'
}
