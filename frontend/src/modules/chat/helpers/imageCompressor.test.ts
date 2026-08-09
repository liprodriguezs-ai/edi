/**
 * Tests para la utilidad de compresión de imágenes a WebP.
 * jsdom no tiene canvas real, así que mockeamos las APIs del browser
 * (Image, createObjectURL/revokeObjectURL, createElement('canvas')).
 */

import {
  compressImageToWebP,
  isAllowedAttachment,
  isValidAttachmentSize,
  isValidImageFile,
  isValidImageSize,
} from '@/modules/chat/helpers/imageCompressor'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// ── Helpers ─────────────────────────────────────────────────────────────────

function makeFile(name: string, type = 'image/jpeg', size = 1024): File {
  const content = new Uint8Array(size).fill(0)
  return new File([content], name, { type })
}

// ── Mock de APIs del browser ─────────────────────────────────────────────────

let capturedOnLoad: (() => void) | null = null
let capturedOnError: (() => void) | null = null
let capturedSrc = ''

const mockToBlobFn = vi.fn()
const mockDrawImageFn = vi.fn()
const mockGetContextFn = vi.fn()

class MockImage {
  width = 800
  height = 600
  set src(value: string) {
    capturedSrc = value
    // Simula carga asíncrona exitosa
    setTimeout(() => {
      if (capturedOnLoad) capturedOnLoad()
    }, 0)
  }
  get src() {
    return capturedSrc
  }
  set onload(fn: () => void) {
    capturedOnLoad = fn
  }
  set onerror(fn: () => void) {
    capturedOnError = fn
  }
}

beforeEach(() => {
  capturedOnLoad = null
  capturedOnError = null
  capturedSrc = ''
  mockToBlobFn.mockReset()
  mockDrawImageFn.mockReset()
  mockGetContextFn.mockReset()

  vi.stubGlobal('Image', MockImage)

  vi.stubGlobal('URL', {
    createObjectURL: vi.fn(() => 'blob:fake-url'),
    revokeObjectURL: vi.fn(),
  })

  mockGetContextFn.mockReturnValue({ drawImage: mockDrawImageFn })
  mockToBlobFn.mockImplementation(
    (cb: (blob: Blob | null) => void, _type: string, _quality: number) => {
      const fakeBlob = new Blob(['webp-data'], { type: 'image/webp' })
      setTimeout(() => cb(fakeBlob), 0)
    }
  )

  vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
    if (tag === 'canvas') {
      return {
        width: 0,
        height: 0,
        getContext: mockGetContextFn,
        toBlob: mockToBlobFn,
      } as unknown as HTMLCanvasElement
    }
    return document.createElement(tag)
  })
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

// ── Tests ────────────────────────────────────────────────────────────────────

describe('compressImageToWebP', () => {
  it('devuelve un File con mime type image/webp y extensión .webp', async () => {
    const file = makeFile('foto.jpg', 'image/jpeg')
    const result = await compressImageToWebP(file)

    expect(result).toBeInstanceOf(File)
    expect(result.type).toBe('image/webp')
    expect(result.name).toBe('foto.webp')
  })

  it('pasa quality=0.8 y type=image/webp a toBlob', async () => {
    const file = makeFile('imagen.png', 'image/png')
    await compressImageToWebP(file, { quality: 0.8 })

    expect(mockToBlobFn).toHaveBeenCalledWith(expect.any(Function), 'image/webp', 0.8)
  })

  it('escala hacia abajo cuando la imagen supera maxDimension', async () => {
    // La MockImage tiene width=800, height=600 por defecto
    // Con maxDimension=400, el scale debe ser 400/800 = 0.5
    let capturedWidth = 0
    let capturedHeight = 0

    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      if (tag === 'canvas') {
        return {
          get width() {
            return capturedWidth
          },
          set width(v: number) {
            capturedWidth = v
          },
          get height() {
            return capturedHeight
          },
          set height(v: number) {
            capturedHeight = v
          },
          getContext: mockGetContextFn,
          toBlob: mockToBlobFn,
        } as unknown as HTMLCanvasElement
      }
      return document.createElement(tag)
    })

    const file = makeFile('grande.jpg', 'image/jpeg')
    await compressImageToWebP(file, { maxDimension: 400 })

    expect(capturedWidth).toBe(400) // 800 * 0.5
    expect(capturedHeight).toBe(300) // 600 * 0.5
  })

  it('no escala si la imagen ya cabe dentro de maxDimension', async () => {
    // MockImage: 800×600, maxDimension=1600 → scale=1, sin recorte
    let capturedW = 0
    let capturedH = 0
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      if (tag === 'canvas') {
        const c = {
          get width() {
            return capturedW
          },
          set width(v: number) {
            capturedW = v
          },
          get height() {
            return capturedH
          },
          set height(v: number) {
            capturedH = v
          },
          getContext: mockGetContextFn,
          toBlob: mockToBlobFn,
        }
        return c as unknown as HTMLCanvasElement
      }
      return document.createElement(tag)
    })

    const file = makeFile('normal.jpg', 'image/jpeg')
    await compressImageToWebP(file)

    expect(capturedW).toBe(800)
    expect(capturedH).toBe(600)
  })

  it('rechaza la promesa cuando toBlob devuelve null', async () => {
    mockToBlobFn.mockImplementation((cb: (blob: Blob | null) => void) => {
      setTimeout(() => cb(null), 0)
    })

    const file = makeFile('roto.jpg', 'image/jpeg')
    await expect(compressImageToWebP(file)).rejects.toThrow('comprimir')
  })

  it('rechaza la promesa cuando Image dispara onerror', async () => {
    vi.stubGlobal(
      'Image',
      class BadImage {
        width = 0
        height = 0
        set src(_: string) {
          setTimeout(() => {
            if (capturedOnError) capturedOnError()
          }, 0)
        }
        set onload(fn: () => void) {
          capturedOnLoad = fn
        }
        set onerror(fn: () => void) {
          capturedOnError = fn
        }
      }
    )

    const file = makeFile('corrupta.jpg', 'image/jpeg')
    await expect(compressImageToWebP(file)).rejects.toThrow('cargar')
  })
})

describe('isValidImageFile', () => {
  it.each([
    ['image/jpeg', true],
    ['image/png', true],
    ['image/webp', true],
    ['image/gif', true],
    ['image/bmp', false],
    ['image/tiff', false],
    ['application/pdf', false],
    ['text/plain', false],
  ])('tipo %s → válido=%s', (type, expected) => {
    const file = makeFile('test', type)
    expect(isValidImageFile(file)).toBe(expected)
  })
})

describe('isValidImageSize', () => {
  it('acepta archivos de exactamente 5 MB', () => {
    const file = makeFile('exacto.jpg', 'image/jpeg', 5 * 1024 * 1024)
    expect(isValidImageSize(file)).toBe(true)
  })

  it('rechaza archivos que superan 5 MB', () => {
    const file = makeFile('grande.jpg', 'image/jpeg', 5 * 1024 * 1024 + 1)
    expect(isValidImageSize(file)).toBe(false)
  })

  it('acepta archivos pequeños', () => {
    const file = makeFile('chico.jpg', 'image/jpeg', 512)
    expect(isValidImageSize(file)).toBe(true)
  })
})

describe('isAllowedAttachment', () => {
  it.each([
    ['documento.pdf', true],
    ['foto.jpg', true],
    ['foto.jpeg', true],
    ['imagen.png', true],
    ['imagen.webp', true],
    ['animado.gif', true],
    ['video.mp4', true],
    ['audio.mp3', true],
    ['reporte.doc', true],
    ['reporte.docx', true],
    ['tabla.xls', true],
    ['tabla.xlsx', true],
    ['comprimido.zip', false],
    ['ejecutable.exe', false],
    ['sinextension', false],
  ])('archivo %s → permitido=%s', (name, expected) => {
    const file = makeFile(name)
    expect(isAllowedAttachment(file)).toBe(expected)
  })

  it('es insensible a mayúsculas en la extensión', () => {
    const file = makeFile('documento.PDF')
    expect(isAllowedAttachment(file)).toBe(true)
  })
})

describe('isValidAttachmentSize', () => {
  it('acepta archivos de exactamente 10 MB', () => {
    const file = makeFile('exacto.pdf', 'application/pdf', 10 * 1024 * 1024)
    expect(isValidAttachmentSize(file)).toBe(true)
  })

  it('rechaza archivos que superan 10 MB', () => {
    const file = makeFile('grande.pdf', 'application/pdf', 10 * 1024 * 1024 + 1)
    expect(isValidAttachmentSize(file)).toBe(false)
  })

  it('acepta archivos pequeños', () => {
    const file = makeFile('chico.pdf', 'application/pdf', 512)
    expect(isValidAttachmentSize(file)).toBe(true)
  })
})
