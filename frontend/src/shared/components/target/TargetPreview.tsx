import { isSelectorReady } from '@/shared/utils/targetSelector'
import { useQuery } from '@tanstack/react-query'
import { Users } from 'lucide-react'
import type { ReactNode } from 'react'

interface TargetPreviewProps {
  /**
   * Discriminador de caché por endpoint. Dos consumidores pueden pedir el MISMO alcance
   * (ej. `all`) y significar cosas distintas —cobranza cuenta UNIDADES, avisos cuentan
   * RESIDENTES—; sin este scope compartirían una entrada de caché de React Query y uno
   * podría pintar el conteo del otro. Ej.: `'charge-batch'` | `'announcement'`.
   */
  previewScope: string
  /** Valor actual de `target_type`. */
  targetType: string
  /** Selector de alcance ya construido (payload `target_selector`). */
  previewSelector: Record<string, unknown> | null
  /** Fetcher del conteo de destinatarios. Endpoint-agnóstico: lo inyecta el consumidor. */
  fetcher: (_payload: {
    target_type: string
    target_selector?: Record<string, unknown> | null
  }) => Promise<number>
  /** Sustantivo del conteo (p. ej. `'residentes'` | `'unidades incluidas'`). */
  noun: string
}

const BOX_CLASS =
  'flex items-center gap-2 rounded-[10px] bg-[var(--green-bg)] px-3.5 py-2.5 text-[13px] text-[var(--green-deep)]'
const MUTED_CLASS =
  'flex items-center gap-2 rounded-[10px] bg-[var(--surface-2)] px-3.5 py-2.5 text-[13px] text-[var(--ink-3)]'

/**
 * Vista previa en vivo del conteo de destinatarios del alcance. Endpoint-agnóstico: el
 * `fetcher` lo inyecta el consumidor (cobranza -> unidades del lote; avisos -> residentes).
 * El padre (`SingleTargetSelector`) sólo lo monta cuando recibe un `previewFetcher`, así el
 * hook `useQuery` nunca se llama de forma condicional. La query se gatea por `isSelectorReady`
 * (no dispara con un alcance incompleto) y la clave lleva `previewScope` para que dos
 * consumidores con distinto significado NO compartan la misma entrada de caché. El bloque es
 * un live-region (`role="status"`) para que un lector de pantalla anuncie el conteo al cambiar.
 */
export function TargetPreview({
  previewScope,
  targetType,
  previewSelector,
  fetcher,
  noun,
}: TargetPreviewProps) {
  const ready = isSelectorReady(targetType, previewSelector)
  const {
    data: count,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ['target-preview', previewScope, targetType, previewSelector],
    queryFn: () => fetcher({ target_type: targetType, target_selector: previewSelector }),
    enabled: Boolean(fetcher) && ready,
    staleTime: 10_000,
  })

  // Cuatro estados distinguibles: incompleto (no dispara), cargando, error y éxito. Un 0 real
  // se muestra como "0 …" (no como "…"), y el error no se confunde con cargando.
  let muted = false
  let body: ReactNode
  if (!ready) {
    muted = true
    body = 'Selecciona el alcance para ver la vista previa'
  } else if (isLoading) {
    body = 'Calculando…'
  } else if (isError) {
    muted = true
    body = 'No se pudo calcular el alcance'
  } else {
    body = (
      <>
        Vista previa:{' '}
        <b className='font-semibold'>
          {count ?? 0} {noun}
        </b>
      </>
    )
  }

  return (
    <div role='status' aria-live='polite' className={muted ? MUTED_CLASS : BOX_CLASS}>
      <Users className='size-4 flex-none' aria-hidden />
      {body}
    </div>
  )
}
