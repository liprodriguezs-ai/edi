/**
 * Compone una TARJETA de QR lista para compartir: el código QR más el texto
 * (nombre, unidad, código, vigencia) dibujados DENTRO de la misma imagen.
 *
 * Por qué existe: al compartir con el Web Share API mandamos `text` junto al
 * archivo, pero varios destinos —WhatsApp entre ellos— descartan el texto
 * cuando viene un adjunto. Quemando los datos en el PNG, la información llega
 * completa sin importar la app que la reciba.
 *
 * Todo el layout se declara en unidades lógicas y se dibuja con un factor de
 * escala, para que el PNG salga nítido en pantallas de alta densidad.
 */

const SCALE = 2
const WIDTH = 384
const PAD = 28

/** Paleta "EDI · v3 Salvia" (index.css). Literal: el canvas no lee CSS vars. */
const COLOR = {
  headerBg: '#21402c',
  headerInk: '#ffffff',
  headerMuted: '#afc2ad',
  cardBg: '#ffffff',
  ink: '#1b201c',
  inkMuted: '#888d83',
  border: '#e8e5dd',
  hairline: '#f0eee7',
  surface: '#faf9f5',
  brand: '#33935d',
} as const

const FONT_SANS = '"Hanken Grotesk", system-ui, -apple-system, sans-serif'
const FONT_MONO = 'ui-monospace, SFMono-Regular, Menlo, monospace'

const HEADER_H = 74
const QR_BOX = 244
const QR_INNER = 204
const CODE_H = 60
const ROW_H = 27
const NOTE_LINE_H = 15

export interface QrCardRow {
  label: string
  value: string
}

export interface QrCardOptions {
  /** Canvas con el QR ya renderizado (se redibuja escalado). */
  qrCanvas: HTMLCanvasElement
  /** Nombre de marca en la cabecera. */
  brand: string
  /** Título de la cabecera, ej. "QR de acceso". */
  heading: string
  /** Nombre del visitante; se omite la línea si viene vacío. */
  name?: string
  /** Código en texto plano, por si el escáner falla. */
  code: string
  /** Filas de metadatos (unidad, motivo, vigencia…). */
  rows: QrCardRow[]
  /** Instrucción al pie. */
  note: string
}

/** Aplica `letterSpacing` solo si el navegador lo soporta (Safari < 17.4 no). */
function setTracking(ctx: CanvasRenderingContext2D, value: string): void {
  if ('letterSpacing' in ctx) {
    ctx.letterSpacing = value
  }
}

/** Corta el texto con puntos suspensivos si no cabe en `maxWidth`. */
function truncate(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string {
  if (ctx.measureText(text).width <= maxWidth) {
    return text
  }

  let result = text
  while (result.length > 1 && ctx.measureText(`${result}…`).width > maxWidth) {
    result = result.slice(0, -1)
  }
  return `${result}…`
}

/** Parte el texto en líneas que quepan en `maxWidth`. */
function wrap(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const lines: string[] = []
  let current = ''

  for (const word of text.split(' ')) {
    const candidate = current ? `${current} ${word}` : word
    if (current && ctx.measureText(candidate).width > maxWidth) {
      lines.push(current)
      current = word
    } else {
      current = candidate
    }
  }

  if (current) {
    lines.push(current)
  }
  return lines
}

/** `roundRect` no existe en navegadores viejos: degradamos a esquinas rectas. */
function roundedRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number
): void {
  ctx.beginPath()
  if (typeof ctx.roundRect === 'function') {
    ctx.roundRect(x, y, width, height, radius)
  } else {
    ctx.rect(x, y, width, height)
  }
}

/** Cabecera verde con la marca y el título. */
function drawHeader(ctx: CanvasRenderingContext2D, options: QrCardOptions): void {
  ctx.fillStyle = COLOR.headerBg
  ctx.fillRect(0, 0, WIDTH, HEADER_H)

  ctx.textAlign = 'left'
  ctx.fillStyle = COLOR.headerMuted
  ctx.font = `600 10px ${FONT_SANS}`
  setTracking(ctx, '1.6px')
  ctx.fillText(options.brand.toUpperCase(), PAD, 30)
  setTracking(ctx, '0px')

  ctx.fillStyle = COLOR.headerInk
  ctx.font = `600 19px ${FONT_SANS}`
  ctx.fillText(options.heading, PAD, 55)
}

/** Marco blanco con borde y el QR centrado adentro. */
function drawQr(ctx: CanvasRenderingContext2D, qrCanvas: HTMLCanvasElement, y: number): void {
  const x = (WIDTH - QR_BOX) / 2

  roundedRect(ctx, x, y, QR_BOX, QR_BOX, 16)
  ctx.fillStyle = COLOR.cardBg
  ctx.fill()
  ctx.strokeStyle = COLOR.border
  ctx.lineWidth = 1
  ctx.stroke()

  const inner = (QR_BOX - QR_INNER) / 2
  ctx.drawImage(qrCanvas, x + inner, y + inner, QR_INNER, QR_INNER)
}

/** Bloque del código en texto plano sobre superficie tenue. */
function drawCode(ctx: CanvasRenderingContext2D, code: string, y: number): void {
  const width = WIDTH - PAD * 2

  roundedRect(ctx, PAD, y, width, CODE_H, 12)
  ctx.fillStyle = COLOR.surface
  ctx.fill()
  ctx.strokeStyle = COLOR.border
  ctx.lineWidth = 1
  ctx.stroke()

  ctx.textAlign = 'center'
  ctx.fillStyle = COLOR.inkMuted
  ctx.font = `600 9px ${FONT_SANS}`
  setTracking(ctx, '1.4px')
  ctx.fillText('CÓDIGO', WIDTH / 2, y + 21)
  setTracking(ctx, '0px')

  ctx.fillStyle = COLOR.ink
  ctx.font = `600 19px ${FONT_MONO}`
  setTracking(ctx, '2px')
  ctx.fillText(truncate(ctx, code, width - 24), WIDTH / 2, y + 45)
  setTracking(ctx, '0px')
}

/** Filas etiqueta/valor separadas por hairlines. */
function drawRows(ctx: CanvasRenderingContext2D, rows: QrCardRow[], startY: number): void {
  rows.forEach((row, index) => {
    const y = startY + index * ROW_H

    if (index > 0) {
      ctx.strokeStyle = COLOR.hairline
      ctx.lineWidth = 1
      ctx.beginPath()
      ctx.moveTo(PAD, y - 9)
      ctx.lineTo(WIDTH - PAD, y - 9)
      ctx.stroke()
    }

    ctx.textAlign = 'left'
    ctx.fillStyle = COLOR.inkMuted
    ctx.font = `400 12px ${FONT_SANS}`
    ctx.fillText(row.label, PAD, y + 6)

    ctx.textAlign = 'right'
    ctx.fillStyle = COLOR.ink
    ctx.font = `600 12px ${FONT_SANS}`
    const labelWidth = ctx.measureText(row.label).width
    ctx.fillText(truncate(ctx, row.value, WIDTH - PAD * 2 - labelWidth - 16), WIDTH - PAD, y + 6)
  })
}

/**
 * Dibuja la tarjeta completa y devuelve el canvas resultante.
 * Devuelve null si el navegador no da contexto 2D.
 */
export async function buildQrCardCanvas(options: QrCardOptions): Promise<HTMLCanvasElement | null> {
  // Sin esto la tipografía de marca puede no estar lista y el canvas cae a la
  // fuente del sistema (la imagen saldría con otra letra que el resto del app).
  if (typeof document !== 'undefined' && document.fonts?.ready) {
    await document.fonts.ready
  }

  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) {
    return null
  }

  const contentWidth = WIDTH - PAD * 2
  const hasName = Boolean(options.name)

  // Pasada de medición: el alto depende de cuántas líneas ocupe la nota.
  ctx.font = `400 11px ${FONT_SANS}`
  const noteLines = wrap(ctx, options.note, contentWidth)

  const nameH = hasName ? 30 : 0
  const rowsH = options.rows.length * ROW_H
  const height =
    HEADER_H +
    20 +
    nameH +
    QR_BOX +
    18 +
    CODE_H +
    22 +
    rowsH +
    16 +
    noteLines.length * NOTE_LINE_H +
    PAD

  canvas.width = WIDTH * SCALE
  canvas.height = height * SCALE
  // Resetear el tamaño limpia el estado del contexto: escalar va después.
  ctx.scale(SCALE, SCALE)
  ctx.textBaseline = 'alphabetic'

  ctx.fillStyle = COLOR.cardBg
  ctx.fillRect(0, 0, WIDTH, height)

  drawHeader(ctx, options)

  let y = HEADER_H + 20

  if (options.name) {
    ctx.textAlign = 'center'
    ctx.fillStyle = COLOR.ink
    ctx.font = `600 21px ${FONT_SANS}`
    ctx.fillText(truncate(ctx, options.name, contentWidth), WIDTH / 2, y + 18)
    y += nameH
  }

  drawQr(ctx, options.qrCanvas, y)
  y += QR_BOX + 18

  drawCode(ctx, options.code, y)
  y += CODE_H + 22

  drawRows(ctx, options.rows, y)
  y += rowsH + 16

  ctx.textAlign = 'center'
  ctx.fillStyle = COLOR.inkMuted
  ctx.font = `400 11px ${FONT_SANS}`
  noteLines.forEach((line, index) => {
    ctx.fillText(line, WIDTH / 2, y + index * NOTE_LINE_H)
  })

  // Filete de marca al pie: cierra la tarjeta y la hace reconocible.
  ctx.fillStyle = COLOR.brand
  ctx.fillRect(0, height - 4, WIDTH, 4)

  return canvas
}
