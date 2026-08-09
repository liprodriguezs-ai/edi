import { shareQr } from '@/shared/utils/shareQr'
import { afterEach, describe, expect, it, vi } from 'vitest'

/** Canvas mínimo: solo necesitamos `toBlob` para armar el File PNG. */
function fakeCanvas(
  blob: Blob | null = new Blob(['png'], { type: 'image/png' })
): HTMLCanvasElement {
  return {
    toBlob: (callback: BlobCallback) => callback(blob),
  } as unknown as HTMLCanvasElement
}

/** Deja `navigator` con exactamente las capacidades que declara el caso. */
function stubNavigator(capabilities: Partial<Navigator>): void {
  vi.stubGlobal('navigator', capabilities as Navigator)
}

const baseOptions = {
  title: 'QR de acceso',
  text: 'Visita para Casa 12 — código ABC123.',
  fileName: 'qr-visita.png',
}

describe('shareQr', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('comparte la imagen PNG cuando el navegador soporta archivos', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    stubNavigator({ share, canShare: () => true })

    const result = await shareQr({ ...baseOptions, canvas: fakeCanvas() })

    expect(result).toBe('shared-image')
    const payload = share.mock.calls[0][0] as ShareData
    expect(payload.files).toHaveLength(1)
    expect(payload.files?.[0].name).toBe('qr-visita.png')
    expect(payload.files?.[0].type).toBe('image/png')
    expect(payload.text).toBe(baseOptions.text)
  })

  it('cae a compartir solo texto cuando canShare rechaza el archivo', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    stubNavigator({ share, canShare: () => false })

    const result = await shareQr({ ...baseOptions, canvas: fakeCanvas() })

    expect(result).toBe('shared-text')
    expect((share.mock.calls[0][0] as ShareData).files).toBeUndefined()
  })

  it('cae a compartir solo texto cuando no hay canvas', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    stubNavigator({ share, canShare: () => true })

    const result = await shareQr({ ...baseOptions, canvas: null })

    expect(result).toBe('shared-text')
  })

  it('copia al portapapeles cuando no existe el Web Share API', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    stubNavigator({ clipboard: { writeText } as unknown as Clipboard })

    const result = await shareQr({ ...baseOptions, canvas: fakeCanvas() })

    expect(result).toBe('copied')
    expect(writeText).toHaveBeenCalledWith(baseOptions.text)
  })

  it('reporta cancelación (AbortError) sin caer al portapapeles', async () => {
    const abort = new Error('cancelado')
    abort.name = 'AbortError'
    const writeText = vi.fn()
    stubNavigator({
      share: vi.fn().mockRejectedValue(abort),
      canShare: () => true,
      clipboard: { writeText } as unknown as Clipboard,
    })

    const result = await shareQr({ ...baseOptions, canvas: fakeCanvas() })

    expect(result).toBe('cancelled')
    expect(writeText).not.toHaveBeenCalled()
  })

  it('devuelve unsupported sin compartir ni portapapeles', async () => {
    stubNavigator({})

    const result = await shareQr({ ...baseOptions, canvas: fakeCanvas() })

    expect(result).toBe('unsupported')
  })
})
