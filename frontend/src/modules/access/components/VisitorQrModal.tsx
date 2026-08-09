import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { VISITOR_STATUS, type IVisitor } from '@/modules/access/schema'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { buildQrCardCanvas, type QrCardRow } from '@/shared/utils/buildQrCard'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { shareQr } from '@/shared/utils/shareQr'
import { toast } from '@/shared/hooks/useToast'
import { Download, Share2, Ban } from 'lucide-react'
import { useRef } from 'react'
import { QRCodeSVG, QRCodeCanvas } from 'qrcode.react'

interface VisitorQrModalProps {
  /** Visita a mostrar; null cierra el modal. */
  visitor: IVisitor | null
  onClose: () => void
  /** Callback para iniciar el flujo de cancelación (se delega al padre). */
  onCancel?: (_visitor: IVisitor) => void
}

/**
 * Muestra el código QR de una visita para que el residente lo comparta con su
 * visitante. Incluye acciones de descarga (PNG), compartir (Web Share API o
 * clipboard fallback) y cancelar QR (solo si la visita está en estado PENDING).
 */
export function VisitorQrModal({ visitor, onClose, onCancel }: VisitorQrModalProps) {
  const token = visitor?.qr_token ?? ''
  const canvasRef = useRef<HTMLCanvasElement>(null)

  const isPending = visitor?.visitor_status_id === VISITOR_STATUS.PENDING
  /** Mismo nombre para el archivo que se descarga y el que se comparte. */
  const qrFileName = `qr-${visitor?.name ?? 'visita'}.png`

  function handleDownload() {
    const canvas = canvasRef.current
    if (!canvas) return
    const url = canvas.toDataURL('image/png')
    const a = document.createElement('a')
    a.href = url
    a.download = qrFileName
    a.click()
  }

  /** Metadatos que se imprimen en la tarjeta compartida. */
  function buildCardRows(): QrCardRow[] {
    const rows: QrCardRow[] = []
    const unitLabel = visitor?.unit?.full_identifier ?? visitor?.unit?.number

    if (unitLabel) {
      rows.push({ label: 'Unidad', value: unitLabel })
    }
    if (visitor?.visitor_motive?.name) {
      rows.push({ label: 'Motivo', value: visitor.visitor_motive.name })
    }
    if (visitor?.qr_expires_at) {
      rows.push({ label: 'Vigente hasta', value: displayFormatDate(visitor.qr_expires_at) })
    }
    return rows
  }

  async function handleShare() {
    const unitLabel = visitor?.unit?.full_identifier ?? visitor?.unit?.number ?? ''
    const text = `Visita para ${unitLabel} — código ${token}. Muéstralo en la caseta.`
    const title = `QR de acceso · ${visitor?.name ?? 'Visita'}`

    // La tarjeta lleva los datos IMPRESOS en la imagen: varios destinos
    // (WhatsApp) descartan el `text` cuando comparten un adjunto.
    const qrCanvas = canvasRef.current
    const card = qrCanvas
      ? await buildQrCardCanvas({
          qrCanvas,
          brand: APP_CONFIG.brandName,
          heading: 'QR de acceso',
          name: visitor?.name ?? undefined,
          code: token,
          rows: buildCardRows(),
          note: 'Muestra este código en la caseta. El guardia lo escanea para registrar tu acceso.',
        })
      : null

    const result = await shareQr({
      // Si la composición falla, se comparte el QR pelón antes que nada.
      canvas: card ?? qrCanvas,
      title,
      text,
      fileName: qrFileName,
    })

    if (result === 'copied') {
      toast.success('Código copiado al portapapeles')
    } else if (result === 'unsupported') {
      toast.error('Compartir no disponible en este navegador')
    }
  }

  return (
    <Dialog open={Boolean(visitor)} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='max-w-sm' aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>QR de acceso{visitor?.name ? ` · ${visitor.name}` : ''}</DialogTitle>
        </DialogHeader>

        {token ? (
          <div className='flex flex-col items-center gap-4 py-2'>
            {/* data-allow-zoom: excepción al bloqueo global de pinch (setupZoomLock). */}
            <div
              className='rounded-2xl border border-border bg-white p-4 shadow-sm'
              data-allow-zoom
            >
              <QRCodeSVG value={token} size={208} level='M' marginSize={1} />
            </div>

            {/* Canvas oculto para la descarga PNG */}
            <div className='sr-only' aria-hidden>
              <QRCodeCanvas ref={canvasRef} value={token} size={512} level='M' marginSize={2} />
            </div>

            <div className='w-full space-y-1.5 text-center'>
              {visitor?.unit && (
                <p className='text-[13px] text-[var(--ink-3)]'>
                  Unidad:{' '}
                  <span className='font-medium text-foreground'>
                    {visitor.unit.full_identifier ?? visitor.unit.number ?? '—'}
                  </span>
                </p>
              )}
              {visitor?.visitor_motive?.name && (
                <p className='text-[13px] text-[var(--ink-3)]'>
                  Motivo:{' '}
                  <span className='font-medium text-foreground'>{visitor.visitor_motive.name}</span>
                </p>
              )}
              {visitor?.qr_expires_at && (
                <p className='text-[13px] text-[var(--ink-3)]'>
                  Vigente hasta:{' '}
                  <span className='font-medium text-foreground'>
                    {displayFormatDate(visitor.qr_expires_at)}
                  </span>
                </p>
              )}
            </div>

            <div className='w-full rounded-[10px] bg-[var(--surface-2)] px-3 py-2 text-center'>
              <p className='text-[10.5px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                Código
              </p>
              <p className='select-all font-mono text-[15px] tracking-[0.12em]'>{token}</p>
            </div>

            <p className='px-2 text-center text-[12px] text-[var(--ink-3)]'>
              Muéstralo en la caseta o compártelo con tu visitante. El guardia lo escanea para
              registrar el acceso.
            </p>
          </div>
        ) : (
          <p className='py-8 text-center text-[13px] text-[var(--ink-3)]'>
            Esta visita no tiene un QR activo.
          </p>
        )}

        <DialogFooter className='flex-col gap-2 sm:flex-col sm:space-x-0'>
          {token ? (
            <>
              {/* Acción primaria (compartir con el visitante) + secundaria (descargar) */}
              <div className='grid w-full grid-cols-2 gap-2'>
                <Button type='button' onClick={() => void handleShare()} className='w-full gap-2'>
                  <Share2 className='size-4' />
                  Compartir
                </Button>
                <Button
                  type='button'
                  variant='secondary'
                  onClick={handleDownload}
                  className='w-full gap-2'
                >
                  <Download className='size-4' />
                  Descargar
                </Button>
              </div>
              {isPending && onCancel && visitor && (
                <Button
                  type='button'
                  variant='ghost'
                  className='w-full gap-2 text-destructive hover:bg-destructive/10 hover:text-destructive'
                  onClick={() => {
                    onCancel(visitor)
                    onClose()
                  }}
                >
                  <Ban className='size-4' />
                  Cancelar QR
                </Button>
              )}
            </>
          ) : (
            <Button type='button' variant='outline' onClick={onClose} className='w-full'>
              Cerrar
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
