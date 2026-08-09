import { useEffect, useRef } from 'react'
import { Html5Qrcode } from 'html5-qrcode'

interface QrScannerViewProps {
  onScan: (_code: string) => void
  onPermissionDenied: () => void
}

// Stable DOM element ID — only one QR scanner is ever open at once (inside the pickup modal).
const SCANNER_ELEMENT_ID = 'pkg-pickup-qr-scanner'

/**
 * Camera-based QR scanner for the package pickup flow.
 *
 * Mounts an Html5Qrcode instance on a div with a stable ID.
 * On first successful decode it calls onScan (trimmed text) and stops the camera.
 * On camera-permission denied or no camera it calls onPermissionDenied.
 * The camera stream is always stopped when the component unmounts.
 */
export function QrScannerView({ onScan, onPermissionDenied }: QrScannerViewProps) {
  // Keep callback refs current so the one-shot scanner effect always calls the
  // latest prop values without needing to restart the camera on every re-render.
  // Updating refs inside useEffect (not directly during render) satisfies React Compiler.
  const onScanRef = useRef(onScan)
  const onPermissionDeniedRef = useRef(onPermissionDenied)
  useEffect(() => { onScanRef.current = onScan }, [onScan])
  useEffect(() => { onPermissionDeniedRef.current = onPermissionDenied }, [onPermissionDenied])

  useEffect(() => {
    let scanner: Html5Qrcode | null = null
    let started = false
    let scanned = false
    let unmounted = false

    const startScanner = async () => {
      try {
        scanner = new Html5Qrcode(SCANNER_ELEMENT_ID)

        await scanner.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 240, height: 240 } },
          (decodedText) => {
            if (scanned || unmounted) return
            scanned = true
            // Stop after the first successful read to free the camera immediately.
            scanner?.stop().catch(() => {})
            started = false
            onScanRef.current(decodedText.trim())
          },
          undefined,
        )

        started = true

        // If cleanup already ran while we were awaiting scanner.start, stop now.
        if (unmounted) {
          scanner.stop().catch(() => {})
          started = false
        }
      } catch {
        if (!unmounted) {
          onPermissionDeniedRef.current()
        }
      }
    }

    void startScanner()

    return () => {
      unmounted = true
      if (scanner && started) {
        scanner.stop().catch(() => {})
      }
    }
  }, []) // intentional: scanner lifecycle is tied to mount/unmount only

  return (
    <div
      id={SCANNER_ELEMENT_ID}
      className="w-full overflow-hidden rounded-[10px] bg-black"
      role="img"
      aria-label="Vista de cámara para escanear código QR"
    />
  )
}
