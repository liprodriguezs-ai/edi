import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import type { IMyPackage } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { PackageStatusBadge } from '@/modules/package/components/PackageStatusBadge'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useQuery } from '@tanstack/react-query'
import { Package2, QrCode, Share2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { QRCodeSVG } from 'qrcode.react'

// ─── QR dialog ────────────────────────────────────────────────────────────────

interface QrDialogProps {
  pkg: IMyPackage | null
  onClose: () => void
}

function QrDialog({ pkg, onClose }: QrDialogProps) {
  // Hook must run before early return (React rules of hooks).
  // The query is disabled when there is no active package, so no unnecessary calls.
  const policyQuery = useQuery({
    queryKey: ['package-pickup-policy'],
    queryFn: () => PackageResource.pickupPolicy(),
    enabled: Boolean(pkg?.pickup_code),
    staleTime: 120_000,
    retry: false,
  })

  if (!pkg?.pickup_code) return null

  const disclaimer = policyQuery.data?.liability_disclaimer ?? null
  const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'

  const handleShare = async () => {
    if (!canShare) return
    try {
      await navigator.share({
        title: 'Código de retiro de paquete',
        text: `Mi código de retiro en portería: ${pkg.pickup_code}`,
      })
    } catch {
      // User cancelled share or share not supported — ignore.
    }
  }

  return (
    <Dialog
      open={Boolean(pkg)}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent className='sm:max-w-[360px]'>
        <DialogHeader>
          <DialogTitle>Código de retiro</DialogTitle>
        </DialogHeader>

        <div className='flex flex-col items-center gap-5 py-2'>
          {/* data-allow-zoom: excepción al bloqueo global de pinch (setupZoomLock). */}
          <div
            className='rounded-[16px] border border-border bg-white p-4 shadow-sm'
            data-allow-zoom
          >
            <QRCodeSVG value={pkg.pickup_code} size={200} level='Q' marginSize={0} />
          </div>

          <div className='w-full rounded-[12px] border border-border bg-[var(--surface-2)] px-4 py-3 text-center'>
            <p className='text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--ink-3)]'>
              Código
            </p>
            <p className='mt-1 select-all font-mono text-[22px] font-semibold tracking-[0.18em] text-foreground'>
              {pkg.pickup_code}
            </p>
          </div>

          <p className='text-center text-[12.5px] text-muted-foreground'>
            Muestra este código en la portería para que el guardia escanee y entregue tus paquetes.
          </p>

          {/* Liability disclaimer — shown when configured by the cluster admin */}
          {disclaimer && (
            <p className='w-full rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-center text-[12px] text-amber-900'>
              {disclaimer}
            </p>
          )}

          {canShare && (
            <Button
              type='button'
              variant='outline'
              size='sm'
              className='flex items-center gap-1.5'
              onClick={() => void handleShare()}
            >
              <Share2 className='size-4' />
              Compartir código
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}

// ─── Package card ─────────────────────────────────────────────────────────────

function MyPackageCard({
  pkg,
  onShowQr,
}: {
  pkg: IMyPackage
  onShowQr: (_pkg: IMyPackage) => void
}) {
  const isReady = pkg.status?.key === 'ready' && Boolean(pkg.pickup_code)

  return (
    <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
      <div className='flex items-start justify-between gap-3'>
        <div className='flex items-center gap-3'>
          <span className='grid size-10 flex-none place-items-center rounded-xl bg-[var(--surface-2)] text-muted-foreground'>
            <Package2 className='size-5' />
          </span>
          <div>
            <p className='text-sm font-semibold'>
              {pkg.carrier?.name ?? pkg.carrier_other ?? 'Paquete'}
            </p>
            {pkg.type?.name && <p className='text-[12px] text-muted-foreground'>{pkg.type.name}</p>}
          </div>
        </div>
        <PackageStatusBadge
          statusKey={pkg.status?.key ?? null}
          statusName={pkg.status?.name ?? null}
          statusColor={pkg.status?.color ?? null}
        />
      </div>

      <div className='mt-3 space-y-1 text-[12.5px] text-muted-foreground'>
        {pkg.tracking_number && (
          <p>
            <span className='font-medium text-foreground'>Rastreo:</span>{' '}
            <span className='font-mono'>{pkg.tracking_number}</span>
          </p>
        )}
        {pkg.description && (
          <p>
            <span className='font-medium text-foreground'>Descripción:</span> {pkg.description}
          </p>
        )}
        {pkg.storage_location && (
          <p>
            <span className='font-medium text-foreground'>Almacén:</span> {pkg.storage_location}
          </p>
        )}
        {pkg.received_at && (
          <p>
            <span className='font-medium text-foreground'>Recibido:</span>{' '}
            {displayFormatDate(pkg.received_at, 'mediumDate')}
          </p>
        )}
        {pkg.picked_up_at && (
          <p>
            <span className='font-medium text-foreground'>Retirado:</span>{' '}
            {displayFormatDate(pkg.picked_up_at, 'mediumDate')}
          </p>
        )}
        {pkg.pickup_proxy_name && (
          <p>
            <span className='font-medium text-foreground'>Retirado por:</span>{' '}
            {pkg.pickup_proxy_name}
            {pkg.pickup_proxy_relationship && ` (${pkg.pickup_proxy_relationship})`}
          </p>
        )}
      </div>

      <div className='mt-4 flex flex-wrap items-center gap-3 border-t border-[var(--line-2)] pt-4'>
        {isReady && (
          <Button
            type='button'
            size='sm'
            className='flex items-center gap-1.5'
            onClick={() => onShowQr(pkg)}
          >
            <QrCode className='size-4' />
            Ver código de retiro
          </Button>
        )}
        <Link
          to={`/package/${pkg.id}`}
          className='text-[12.5px] text-[var(--ink-3)] underline underline-offset-2 hover:text-foreground'
        >
          Ver detalle
        </Link>
      </div>
      {isReady && (
        <p className='mt-1.5 text-[11px] text-muted-foreground'>
          Muestra el código QR en la portería para retirar tus paquetes.
        </p>
      )}
    </div>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export const MyPackagesPage = () => {
  const [qrPackage, setQrPackage] = useState<IMyPackage | null>(null)

  // Non-terminal packages (ready/pending) — shown in "Pendientes de retiro"
  const pendingQuery = useQuery({
    queryKey: ['my-packages'],
    queryFn: () => PackageResource.myPackages(),
  })

  // Terminal packages (picked_up, returned, incident) — shown in "Historial"
  const historyQuery = useQuery({
    queryKey: ['my-packages-history'],
    queryFn: () => PackageResource.myPackages({ history: 1 }),
  })

  const pending = pendingQuery.data ?? []
  const history = historyQuery.data ?? []
  const isLoading = pendingQuery.isLoading
  const isError = pendingQuery.isError

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Mis paquetes'
        description='Paquetes recibidos en la portería para tu unidad'
      />

      <QrDialog pkg={qrPackage} onClose={() => setQrPackage(null)} />

      {isLoading ? (
        <TableLoadingState />
      ) : isError ? (
        <TableEmptyState
          title='No se pudieron cargar tus paquetes'
          description='Intenta de nuevo más tarde.'
        />
      ) : pending.length === 0 && history.length === 0 ? (
        <TableEmptyState
          title='Sin paquetes'
          description='No tienes paquetes registrados en portería.'
        />
      ) : (
        <div className='space-y-8'>
          {pending.length > 0 && (
            <section>
              <h2 className='mb-4 text-[13px] font-semibold uppercase tracking-[0.08em] text-muted-foreground'>
                Pendientes de retiro ({pending.length})
              </h2>
              <div className='grid gap-4 sm:grid-cols-2 lg:grid-cols-3'>
                {pending.map((pkg) => (
                  <MyPackageCard key={pkg.id} pkg={pkg} onShowQr={setQrPackage} />
                ))}
              </div>
            </section>
          )}

          {history.length > 0 && (
            <section>
              <h2 className='mb-4 text-[13px] font-semibold uppercase tracking-[0.08em] text-muted-foreground'>
                Historial ({history.length})
              </h2>
              <div className='grid gap-4 sm:grid-cols-2 lg:grid-cols-3'>
                {history.map((pkg) => (
                  <MyPackageCard key={pkg.id} pkg={pkg} onShowQr={setQrPackage} />
                ))}
              </div>
            </section>
          )}
        </div>
      )}
    </div>
  )
}
