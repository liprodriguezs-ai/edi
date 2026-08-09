import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import type { IScanResult } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { ApiError } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation } from '@tanstack/react-query'
import { Html5Qrcode } from 'html5-qrcode'
import { CameraOff, CheckCircle2, DoorOpen, Loader2, QrCode, XCircle } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'

interface ScanQrModalProps {
  isOpen: boolean
  onClose: () => void
  /** Refresca la lista de "dentro ahora" tras una entrada válida. */
  onScanned: () => void
  /** Abre el modal de registro manual y cierra este (fallback para QR inválido). */
  onFallbackToManual?: () => void
}

type LastResult = { ok: boolean; text: string; detail?: IScanResult } | null

const CAMERA_REGION_ID = 'scan-qr-camera-region'

/**
 * ¿El navegador puede pedir la cámara? Requiere contexto seguro (HTTPS/localhost)
 * y soporte de `getUserMedia`. En contextos inseguros o sin cámara se oculta la
 * vista de cámara y queda solo el input manual / lector HID.
 */
function canUseCamera(): boolean {
  return typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'
    && typeof navigator.mediaDevices.getUserMedia === 'function'
}

/**
 * Escaneo de QR en la caseta (§14.5): el guardia escanea con la cámara, con un
 * lector HID que teclea el código, o captura el token a mano. POST /access/scan-qr
 * registra la entrada si el QR es válido; si está usado/cancelado/expirado o en
 * lista negra, lo niega con el motivo. La cámara aparece arriba cuando el
 * navegador la soporta; el input manual queda siempre disponible como fallback y
 * mantiene el foco para escanear varias visitas seguidas (lector HID). Cuando el
 * QR es inválido, muestra detalles del visitante y un acceso directo al registro
 * manual.
 */
export function ScanQrModal({ isOpen, onClose, onScanned, onFallbackToManual }: ScanQrModalProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [token, setToken] = useState('')
  const [last, setLast] = useState<LastResult>(null)

  // La cámara es opcional: solo se monta si el navegador la soporta.
  const [cameraSupported] = useState<boolean>(() => canUseCamera())
  const [cameraError, setCameraError] = useState<string | null>(null)
  const scannerRef = useRef<Html5Qrcode | null>(null)

  // Guard de "scan en vuelo": la cámara decodifica fuera del ciclo de React y
  // puede re-disparar el mismo QR en frames sucesivos; sin esto, un segundo
  // decode mientras el primero está volando crearía una entrada duplicada.
  const isPendingRef = useRef(false)
  // Último código ya enviado: la cámara decodifica el MISMO QR en cada frame, así
  // que sin esto se dispararían varias llamadas/entradas para una sola lectura.
  const lastScanRef = useRef('')
  // Promesa del start() de la cámara en vuelo: stopCamera la espera antes de parar
  // para no dejar un stream huérfano si el cierre ocurre durante la apertura.
  const startPromiseRef = useRef<Promise<unknown> | null>(null)

  // Reset transient state on the open transition using React's "adjust state on
  // prop change" pattern (during render, NOT in an effect). Doing this in an
  // effect/timeout would race with a scan result and wipe it.
  const [wasOpen, setWasOpen] = useState(isOpen)
  if (isOpen !== wasOpen) {
    setWasOpen(isOpen)
    if (isOpen) {
      setToken('')
      setLast(null)
      setCameraError(null)
    }
  }

  useEffect(() => {
    if (!isOpen) {
      return
    }
    // Reinicia los guards al abrir: permite volver a escanear (incluso el MISMO
    // código, p. ej. un QR de evento multi-ingreso) en una nueva apertura.
    isPendingRef.current = false
    lastScanRef.current = ''
    // Focus the input for HID readers (DOM sync — the legitimate use of an effect).
    const id = setTimeout(() => inputRef.current?.focus(), 50)
    return () => clearTimeout(id)
  }, [isOpen])

  const mutation = useMutation({
    mutationFn: (value: string) => AccessLogResource.scanQr(value.trim()),
    onSuccess: (result: IScanResult) => {
      // visitor es null en un escaneo de QR personal (ingreso de residente); el
      // nombre vive en el access_log → así el guardia ve quién entró, no "Visitante".
      const name = result.visitor?.name ?? result.access_log?.visitor_name ?? 'Visitante'
      toast.success(`Entrada registrada · ${name}`)
      onScanned()
      // Éxito → cerrar el modal (el toast confirma). Al cambiar isOpen, el efecto
      // de cámara se limpia (detiene el stream). isPendingRef queda como está y se
      // reinicia en la próxima apertura.
      onClose()
    },
    onError: (error) => {
      isPendingRef.current = false
      const detail = error instanceof ApiError
        ? (error.payload as IScanResult | undefined)
        : undefined
      const reason = detail?.reason ?? (error instanceof ApiError ? error.message : 'No se pudo validar el QR')
      toast.error(reason)
      setLast({ ok: false, text: reason, detail })
      setToken('')
      inputRef.current?.focus()
    },
  })

  // Mantener una referencia estable a la mutación para el callback de la cámara,
  // que vive fuera del ciclo de render de React (html5-qrcode lo invoca). Se
  // sincroniza en un efecto para no escribir el ref durante el render.
  const mutateRef = useRef(mutation.mutate)
  useEffect(() => {
    mutateRef.current = mutation.mutate
  }, [mutation.mutate])

  const submit = () => {
    const value = token.trim()
    if (value.length === 0 || isPendingRef.current) return
    // Marca pendiente SINCRÓNICO antes de mutate (evita ráfaga de llamadas).
    isPendingRef.current = true
    lastScanRef.current = value
    mutation.mutate(value)
  }

  /** Detiene la cámara y libera el stream (sin tracks colgados). */
  const stopCamera = useCallback(async () => {
    const scanner = scannerRef.current
    if (!scanner) {
      return
    }
    scannerRef.current = null

    // Espera a que un start() EN VUELO termine de abrir la cámara antes de pararla.
    // html5-qrcode pone isScanning=true recién DESPUÉS de getUserMedia; si paráramos
    // gateando por isScanning durante la apertura, el stream quedaría abierto sin
    // referencia (cámara prendida). El .catch del start ya hace que esto no rechace.
    try {
      await startPromiseRef.current
    } catch {
      // start() falló → no hay stream que liberar.
    }
    startPromiseRef.current = null

    // stop() incondicional: lanza un error inofensivo ("not running") si nunca abrió.
    try {
      await scanner.stop()
    } catch {
      // Detener una cámara ya detenida no es un error accionable.
    }
    try {
      scanner.clear()
    } catch {
      // idem
    }
  }, [])

  // Arranca/detiene la cámara junto con la apertura del modal. La detección de
  // un QR auto-rellena el token y dispara el mismo flujo que el input manual.
  useEffect(() => {
    if (!isOpen || !cameraSupported) {
      return
    }

    let cancelled = false
    let rafId = 0

    // El contenido del Dialog (portal de Radix) puede no estar aún en el DOM en el
    // tick en que corre este efecto, y html5-qrcode LANZA ("HTML Element ... not
    // found") si no encuentra el div por id → tumbaría toda la sección. Esperamos
    // (rAF) a que el contenedor exista y envolvemos en try/catch para que cualquier
    // falla de cámara DEGRADE al input manual y nunca rompa la ruta.
    const startWhenReady = (): void => {
      if (cancelled) {
        return
      }
      if (! document.getElementById(CAMERA_REGION_ID)) {
        rafId = window.requestAnimationFrame(startWhenReady)
        return
      }

      try {
        const scanner = new Html5Qrcode(CAMERA_REGION_ID, { verbose: false })
        scannerRef.current = scanner

        startPromiseRef.current = scanner
          .start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 220 } },
            (decodedText) => {
              const value = decodedText.trim()
              // Ignora vacíos, scans en vuelo (isPendingRef) y re-lecturas del MISMO
              // QR: la cámara decodifica el mismo código en cada frame, así que sin
              // esto se dispararían varias llamadas/entradas por una sola lectura.
              if (value.length === 0 || isPendingRef.current || value === lastScanRef.current) {
                return
              }
              isPendingRef.current = true
              lastScanRef.current = value
              setToken(value)
              mutateRef.current(value)
            },
            undefined,
          )
          .catch(() => {
            if (! cancelled) {
              setCameraError('No se pudo abrir la cámara. Usa el lector o escribe el código.')
            }
          })
      } catch {
        if (! cancelled) {
          setCameraError('No se pudo iniciar la cámara. Usa el lector o escribe el código.')
        }
      }
    }

    startWhenReady()

    return () => {
      cancelled = true
      window.cancelAnimationFrame(rafId)
      void stopCamera()
    }
  }, [isOpen, cameraSupported, stopCamera])

  const handleClose = useCallback(() => {
    void stopCamera()
    onClose()
  }, [onClose, stopCamera])

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
      <DialogContent className="max-w-sm" aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <QrCode className="size-5" />
            Escanear QR de visita
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-3 py-1">
          {/* Vista de cámara — solo cuando el navegador la soporta */}
          {cameraSupported && (
            <div className="space-y-1.5">
              <div
                id={CAMERA_REGION_ID}
                className="overflow-hidden rounded-[12px] border border-border bg-black [&_video]:w-full"
                aria-label="Vista de la cámara para escanear el QR"
              />
              {cameraError ? (
                <p className="flex items-center gap-1.5 text-[12px] text-[#C0533C]">
                  <CameraOff className="size-3.5 shrink-0" />
                  {cameraError}
                </p>
              ) : (
                <p className="text-center text-[12px] text-[var(--ink-3)]">
                  Apunta la cámara al código QR del visitante.
                </p>
              )}
            </div>
          )}

          <Field>
            <FieldLabel htmlFor="scan-token">Código del QR</FieldLabel>
            <Input
              id="scan-token"
              ref={inputRef}
              value={token}
              onChange={(e) => setToken(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submit() } }}
              placeholder="Escanea o escribe el código…"
              autoComplete="off"
              className="font-mono tracking-[0.12em]"
              disabled={mutation.isPending}
            />
          </Field>

          {/* Loading mientras el backend valida el código */}
          {mutation.isPending && (
            <div className="flex items-center justify-center gap-2 rounded-[10px] bg-[var(--surface-2)] px-3 py-2.5 text-[13px] font-medium text-[var(--ink-3)]">
              <Loader2 className="size-4 animate-spin" />
              Validando código…
            </div>
          )}

          {!mutation.isPending && last && (
            <div className={`space-y-2 rounded-[10px] px-3 py-2 text-[13px] ${last.ok ? 'bg-[var(--green-bg)] text-[var(--green-deep)]' : 'bg-[#FBEAE6] text-[#C0533C]'}`}>
              <div className="flex items-center gap-2">
                {last.ok ? <CheckCircle2 className="size-4 shrink-0" /> : <XCircle className="size-4 shrink-0" />}
                <span>{last.text}</span>
              </div>

              {/* Detalle enriquecido cuando el QR es inválido */}
              {!last.ok && last.detail && (
                <div className="space-y-0.5 pl-6 text-[12px] opacity-90">
                  {last.detail.visitor?.name && (
                    <p>Visitante: <span className="font-medium">{last.detail.visitor.name}</span></p>
                  )}
                  {last.detail.visitor?.unit?.full_identifier && (
                    <p>Unidad: <span className="font-medium">{last.detail.visitor.unit.full_identifier}</span></p>
                  )}
                  {last.detail.access_log?.entered_at && (
                    <p>Usado: <span className="font-medium">{displayFormatDate(last.detail.access_log.entered_at)}</span></p>
                  )}
                </div>
              )}

              {/* Fallback al registro manual si el QR no es válido */}
              {!last.ok && onFallbackToManual && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="mt-1 w-full gap-1.5 border-[#C0533C]/30 text-[#C0533C] hover:bg-[#C0533C]/10 hover:text-[#C0533C]"
                  onClick={onFallbackToManual}
                >
                  <DoorOpen className="size-3.5" />
                  Registrar entrada manual
                </Button>
              )}
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={handleClose} disabled={mutation.isPending}>
            Cerrar
          </Button>
          <Button onClick={submit} disabled={mutation.isPending || token.trim().length === 0}>
            {mutation.isPending ? 'Validando…' : 'Registrar entrada'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
