import { buildQrCardCanvas } from '@/shared/utils/buildQrCard'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * jsdom no trae un canvas real, así que espiamos el contexto 2D: lo que nos
 * importa verificar es QUÉ se dibuja (los textos), no cómo se ve el pixel.
 */
interface FakeContext {
  texts: string[]
  images: unknown[]
}

function stubCanvas(): FakeContext {
  const captured: FakeContext = { texts: [], images: [] }

  const ctx = {
    fillText: (text: string) => captured.texts.push(text),
    drawImage: (image: unknown) => captured.images.push(image),
    measureText: (text: string) => ({ width: text.length * 6 }),
    scale: vi.fn(),
    fillRect: vi.fn(),
    rect: vi.fn(),
    roundRect: vi.fn(),
    beginPath: vi.fn(),
    moveTo: vi.fn(),
    lineTo: vi.fn(),
    stroke: vi.fn(),
    fill: vi.fn(),
    font: '',
    fillStyle: '',
    strokeStyle: '',
    lineWidth: 1,
    textAlign: 'left',
    textBaseline: 'alphabetic',
  }

  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(
    ctx as unknown as CanvasRenderingContext2D
  )

  return captured
}

const qrCanvas = document.createElement('canvas')

const baseOptions = {
  qrCanvas,
  brand: 'Kivo7',
  heading: 'QR de acceso',
  code: 'ABC123',
  note: 'Muestra este código en la caseta.',
}

describe('buildQrCardCanvas', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('imprime marca, título, código y filas dentro de la imagen', async () => {
    const captured = stubCanvas()

    const canvas = await buildQrCardCanvas({
      ...baseOptions,
      name: 'Juan Pérez',
      rows: [
        { label: 'Unidad', value: 'Casa 12' },
        { label: 'Vigente hasta', value: '20/08/2026' },
      ],
    })

    expect(canvas).not.toBeNull()
    const printed = captured.texts.join(' | ')
    expect(printed).toContain('KIVO7')
    expect(printed).toContain('QR de acceso')
    expect(printed).toContain('Juan Pérez')
    expect(printed).toContain('ABC123')
    expect(printed).toContain('Casa 12')
    expect(printed).toContain('Vigente hasta')
    expect(printed).toContain('20/08/2026')
  })

  it('dibuja el QR de origen dentro de la tarjeta', async () => {
    const captured = stubCanvas()

    await buildQrCardCanvas({ ...baseOptions, rows: [] })

    expect(captured.images).toEqual([qrCanvas])
  })

  it('omite la línea del nombre cuando no hay visitante', async () => {
    const captured = stubCanvas()

    await buildQrCardCanvas({ ...baseOptions, rows: [] })

    expect(captured.texts).not.toContain('undefined')
  })

  it('crece en alto con cada fila de metadatos', async () => {
    stubCanvas()
    const short = await buildQrCardCanvas({ ...baseOptions, rows: [] })
    const tall = await buildQrCardCanvas({
      ...baseOptions,
      rows: [
        { label: 'Unidad', value: 'Casa 12' },
        { label: 'Motivo', value: 'Visita familiar' },
        { label: 'Vigente hasta', value: '20/08/2026' },
      ],
    })

    expect(tall?.height).toBeGreaterThan(short?.height ?? 0)
  })

  it('devuelve null si el navegador no da contexto 2D', async () => {
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null)

    const canvas = await buildQrCardCanvas({ ...baseOptions, rows: [] })

    expect(canvas).toBeNull()
  })
})
