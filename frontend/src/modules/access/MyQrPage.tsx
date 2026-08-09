import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import type { IMyQr } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { QRCodeSVG } from 'qrcode.react'

/** Ventana de rotación del token (debe coincidir con el TTL del backend). */
const ROTATION_SECONDS = 60
/** Refetchea unos segundos ANTES de expirar para que el token nuevo llegue sin
 *  que el contador llegue a 0 (sin parpadeo de 00:00 en la rotación). */
const ROTATION_LEAD_SECONDS = 2
const RING_SIZE = 296
const RING_STROKE = 10
const RING_RADIUS = (RING_SIZE - RING_STROKE) / 2
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS

/** Formatea segundos restantes como MM:SS. */
function formatCountdown(totalSeconds: number): string {
  const safe = Math.max(0, totalSeconds)
  const minutes = Math.floor(safe / 60)
  const seconds = safe % 60
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

interface QrRingProps {
  /** Segundos restantes a mostrar (lo controla el padre; presentacional). */
  remaining: number
  /** El QR a mostrar dentro del anillo. */
  children: React.ReactNode
}

/**
 * Presentacional: anillo de progreso alrededor del QR + MM:SS del countdown.
 * No tiene estado ni timers — el padre es dueño de `remaining` y de la rotación.
 */
function QrRing({ remaining, children }: QrRingProps) {
  const dashOffset =
    RING_CIRCUMFERENCE * (1 - Math.min(remaining, ROTATION_SECONDS) / ROTATION_SECONDS)
  const isAlmostGone = remaining <= 10

  return (
    <div className='flex flex-col items-center gap-5'>
      <div className='relative' style={{ width: RING_SIZE, height: RING_SIZE }}>
        <svg
          className='absolute inset-0 -rotate-90'
          width={RING_SIZE}
          height={RING_SIZE}
          viewBox={`0 0 ${RING_SIZE} ${RING_SIZE}`}
          aria-hidden
        >
          <circle
            cx={RING_SIZE / 2}
            cy={RING_SIZE / 2}
            r={RING_RADIUS}
            fill='none'
            stroke='var(--surface-2)'
            strokeWidth={RING_STROKE}
          />
          <circle
            cx={RING_SIZE / 2}
            cy={RING_SIZE / 2}
            r={RING_RADIUS}
            fill='none'
            stroke={isAlmostGone ? 'var(--destructive)' : 'var(--brand)'}
            strokeWidth={RING_STROKE}
            strokeLinecap='round'
            strokeDasharray={RING_CIRCUMFERENCE}
            strokeDashoffset={dashOffset}
            className='transition-[stroke-dashoffset] duration-1000 ease-linear'
          />
        </svg>
        <div className='absolute inset-[26px] grid place-items-center rounded-[20px] border border-border bg-white p-4 shadow-sm'>
          {children}
        </div>
      </div>

      <div className='flex flex-col items-center gap-0.5' aria-live='polite'>
        <span
          className={`font-mono text-[34px] font-semibold leading-none tabular-nums tracking-[0.06em] ${
            isAlmostGone ? 'animate-pulse text-[var(--destructive)]' : 'text-foreground'
          }`}
        >
          {formatCountdown(remaining)}
        </span>
        <span className='text-[10.5px] font-medium uppercase tracking-[0.14em] text-[var(--ink-3)]'>
          Se renueva en
        </span>
      </div>
    </div>
  )
}

/**
 * "Mi QR de acceso" (§14.2 personal): el residente muestra su QR rotativo en la
 * caseta para registrar su propio ingreso.
 *
 * ROTACIÓN (estado manual, sin React Query): un ciclo recursivo de `setTimeout`
 * pide un token y se RE-AGENDA solo unos segundos antes de su expiración. Cada
 * fetch llama a `setQr` (re-render garantizado) y re-siembra el contador con el
 * `seconds_remaining` AUTORITATIVO del servidor. Un tick local de 1s baja el
 * número solo para la animación; nunca derivamos la vigencia del reloj local.
 * Al volver a la pestaña (visibilitychange) se pide uno fresco de inmediato.
 */
export const MyQrPage = () => {
  const [qr, setQr] = useState<IMyQr | null>(null)
  const [phase, setPhase] = useState<'loading' | 'error' | 'ok'>('loading')
  const [remaining, setRemaining] = useState(0)

  // Ciclo de fetch + auto-reagendado. El setState vive dentro de funciones async
  // (tras await), no en el cuerpo síncrono del efecto.
  useEffect(() => {
    let active = true
    let timeoutId: number | undefined

    const loadCycle = async (): Promise<void> => {
      try {
        const data = await AccessLogResource.myQr()
        if (!active) {
          return
        }
        setQr(data)
        setPhase('ok')
        setRemaining(data.seconds_remaining)
        window.clearTimeout(timeoutId)
        const nextInMs =
          Math.max(ROTATION_LEAD_SECONDS, data.seconds_remaining - ROTATION_LEAD_SECONDS) * 1000
        timeoutId = window.setTimeout(() => {
          void loadCycle()
        }, nextInMs)
      } catch {
        if (active) {
          setPhase('error')
        }
      }
    }

    // Al volver a la pestaña, el setTimeout pudo haber sido estrangulado en
    // background y el token estar vencido: pedimos uno fresco de inmediato.
    const onVisible = (): void => {
      if (document.visibilityState === 'visible') {
        window.clearTimeout(timeoutId)
        void loadCycle()
      }
    }

    void loadCycle()
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      active = false
      window.clearTimeout(timeoutId)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [])

  // Tick local SOLO para la animación del número/anillo.
  useEffect(() => {
    const intervalId = window.setInterval(() => {
      setRemaining((prev) => Math.max(0, prev - 1))
    }, 1000)
    return () => window.clearInterval(intervalId)
  }, [])

  return (
    <div className='space-y-5'>
      <div>
        <Link
          to='/my-unit'
          className='mb-1 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground'
        >
          <ArrowLeft className='size-3.5' />
          Mi unidad
        </Link>
        <PageHeader
          title='Mi QR de acceso'
          description='Muéstralo en la caseta para registrar tu entrada'
        />
      </div>

      {phase === 'loading' ? (
        <TableLoadingState />
      ) : phase === 'error' || !qr ? (
        <TableEmptyState
          title='No se pudo generar tu QR'
          description='Ocurrió un error al obtener tu código de acceso. Intenta de nuevo más tarde.'
        />
      ) : (
        <div className='mx-auto flex w-full max-w-md flex-col items-center gap-7 rounded-[24px] border border-border bg-card px-8 py-10 shadow-sm'>
          {/* QR con anillo de progreso del countdown alrededor + contador */}
          {/* data-allow-zoom: excepción al bloqueo global de pinch (setupZoomLock),
              para que el residente pueda acercar el QR si el escáner batalla. */}
          <QrRing remaining={remaining}>
            <QRCodeSVG value={qr.token} size={196} level='Q' marginSize={0} data-allow-zoom />
          </QrRing>

          {/* Código en texto plano por si el escáner falla */}
          <div className='w-full rounded-[14px] border border-border bg-[var(--surface-2)] px-4 py-3 text-center'>
            <p className='text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--ink-3)]'>
              Código
            </p>
            <p className='mt-1 select-all font-mono text-[18px] font-semibold tracking-[0.18em] text-foreground'>
              {qr.token}
            </p>
          </div>

          <div className='max-w-sm space-y-1 px-2 text-center'>
            <p className='text-[12.5px] text-[var(--ink-3)]'>
              Este código cambia cada minuto. Muéstralo en la caseta.
            </p>
            <p className='text-[12.5px] text-[var(--ink-3)]'>
              Vigente hasta:{' '}
              <span className='font-medium text-foreground'>
                {displayFormatDate(qr.expires_at)}
              </span>
            </p>
          </div>
        </div>
      )}
    </div>
  )
}
