import SignaturePad from 'signature_pad'
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react'

export interface SignaturePadHandle {
  isEmpty: () => boolean
  clear: () => void
  toBlob: (_callback: (_blob: Blob | null) => void) => void
}

interface SignaturePadCanvasProps {
  /** Called when the user begins a stroke (pointer down). Used to track "has drawn". */
  onBegin?: () => void
  className?: string
}

/**
 * Canvas-based signature pad wrapping the vanilla `signature_pad` library.
 *
 * - Handles high-DPI screens by scaling the canvas element to devicePixelRatio.
 * - Exposes isEmpty / clear / toBlob via a forwarded ref (SignaturePadHandle).
 * - Sets touchAction: none so mobile scrolling does not interfere with drawing.
 */
export const SignaturePadCanvas = forwardRef<SignaturePadHandle, SignaturePadCanvasProps>(
  function SignaturePadCanvas({ onBegin, className }, ref) {
    const canvasRef = useRef<HTMLCanvasElement>(null)
    const padRef = useRef<SignaturePad | null>(null)

    useEffect(() => {
      const canvas = canvasRef.current
      if (!canvas) return

      const pad = new SignaturePad(canvas, { penColor: '#1a1a1a' })
      padRef.current = pad

      // Scale the canvas backing store to devicePixelRatio for crisp strokes
      // on high-DPI screens. Run on mount and on window resize.
      const scaleCanvas = () => {
        const ratio = Math.max(window.devicePixelRatio ?? 1, 1)
        const saved = pad.toData()
        canvas.width = canvas.offsetWidth * ratio
        canvas.height = canvas.offsetHeight * ratio
        const ctx = canvas.getContext('2d')
        if (ctx) ctx.scale(ratio, ratio)
        pad.clear()
        if (saved.length > 0) pad.fromData(saved)
      }

      scaleCanvas()
      window.addEventListener('resize', scaleCanvas)

      return () => {
        window.removeEventListener('resize', scaleCanvas)
        pad.off()
      }
    }, [])

    useImperativeHandle(
      ref,
      () => ({
        isEmpty: () => padRef.current?.isEmpty() ?? true,
        clear: () => padRef.current?.clear(),
        toBlob: (callback) => {
          canvasRef.current?.toBlob(callback, 'image/png')
        },
      }),
      [],
    )

    return (
      <canvas
        ref={canvasRef}
        // onPointerDown fires before signature_pad's own handlers, so it's safe
        // to use it for the "user has started drawing" notification.
        onPointerDown={onBegin}
        className={className}
        aria-label="Área de firma — firme con el dedo o el puntero"
        style={{ touchAction: 'none', cursor: 'crosshair' }}
      />
    )
  },
)
