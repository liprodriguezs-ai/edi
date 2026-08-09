import { PackageResource } from '@/modules/package/services/PackageResource'
import { useModuleAvailable } from '@/shared/hooks/useModuleAvailable'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useQuery } from '@tanstack/react-query'
import { Package2, TriangleAlert } from 'lucide-react'
import { Link } from 'react-router-dom'

/**
 * Widget del dashboard: paquetes pendientes de retiro en portería.
 *
 * Es un conteo CLUSTER-WIDE: solo para staff de vigilancia y mesa directiva.
 * El endpoint `GET /package/report/pending-count` exige create.package O
 * read.report_package (ver PackageReportController::pendingCount), así que el
 * gate del widget debe medir con esa MISMA vara. read.package NO basta:
 * residentes/inquilinos también lo tienen (para ver SUS paquetes) y disparar la
 * petición les devuelve un 403 "Acceso no autorizado".
 *
 * Además, oculto si el tenant no tiene el módulo Paquetería (add-on ausente del
 * menú dinámico → evita el 403 "Módulo no habilitado para este tenant"). Diseño
 * espeja los banners de morosidad (DelinquencyBanner en TreasurerHome).
 *
 * Muestra:
 * - Total de paquetes en portería (status = ready).
 * - Cuántos llevan más de 7 días (bucket d7_plus) — en rojo.
 */
export function PendingPackagesWidget() {
  // Backend gate: create.package (vigilancia) OR read.report_package (mesa) OR * (super-admin).
  const canRead = usePermissions(['create.package', 'read.report_package'])
  const packageAvailable = useModuleAvailable('/package')

  const { data } = useQuery({
    queryKey: ['package-pending-count'],
    queryFn: () => PackageResource.pendingCount(),
    enabled: canRead && packageAvailable,
    staleTime: 0,
    refetchOnMount: 'always',
  })

  if (!canRead || !packageAvailable || !data || data.total === 0) return null

  const hasOld = data.d7_plus > 0

  return (
    <div
      className={`mb-[18px] flex flex-col gap-3 rounded-[14px] border bg-card p-4 shadow-sm sm:flex-row sm:items-center sm:gap-3.5 ${
        hasOld ? 'border-red-200' : 'border-border'
      }`}
    >
      <div className='flex min-w-0 flex-1 items-center gap-3'>
        <span
          className={`grid size-[34px] flex-none place-items-center rounded-lg ${
            hasOld ? 'bg-red-50 text-red-600' : 'bg-[var(--surface-2)] text-[var(--green-deep)]'
          }`}
        >
          {hasOld ? (
            <TriangleAlert className='size-[18px]' aria-hidden />
          ) : (
            <Package2 className='size-[18px]' aria-hidden />
          )}
        </span>
        <div className='min-w-0 text-[13.5px] leading-relaxed'>
          <b className='font-semibold'>
            {data.total} paquete{data.total !== 1 ? 's' : ''} pendiente{data.total !== 1 ? 's' : ''}{' '}
            en portería.
          </b>{' '}
          {hasOld ? (
            <span className='text-red-600'>
              {data.d7_plus} lleva{data.d7_plus !== 1 ? 'n' : ''} más de 7 días sin ser retirado
              {data.d7_plus !== 1 ? 's' : ''}.
            </span>
          ) : (
            <span className='text-[var(--ink-3)]'>Todos recibidos en los últimos 7 días.</span>
          )}
        </div>
      </div>
      <Link
        to='/package'
        className='inline-flex w-full flex-none items-center justify-center gap-1.5 rounded-full border border-border bg-card px-[13px] py-2 text-[12.5px] font-medium text-foreground transition-colors hover:border-[var(--ink-3)] sm:w-auto'
      >
        Ver paquetería
      </Link>
    </div>
  )
}
