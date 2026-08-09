import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import type { IPackage } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { PackagePickupModal } from '@/modules/package/PackagePickupModal'
import { PackageRowActions } from '@/modules/package/components/PackageRowActions'
import { PackageStatusBadge } from '@/modules/package/components/PackageStatusBadge'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ArrowLeft, ScanLine } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Skeleton } from 'boneyard-js/react'

// ─── UUID constant — must match backend PackageStatus::READY ──────────────────
const PACKAGE_STATUS_READY = 'a2001001-0000-4000-8000-000000000002'

// ─── Detail field ─────────────────────────────────────────────────────────────

function DetailField({ label, value }: { label: string; value: React.ReactNode }) {
  if (!value && value !== 0) return null
  return (
    <div className='grid grid-cols-[180px_1fr] gap-2 text-[13px]'>
      <dt className='text-muted-foreground'>{label}</dt>
      <dd className='font-medium text-foreground'>{value}</dd>
    </div>
  )
}

// ─── Photo section ────────────────────────────────────────────────────────────

function PackagePhoto({ pkg }: { pkg: IPackage }) {
  const photo = pkg.media?.find((m) => m.collection_name === 'photo')
  if (!photo) return null

  return (
    <div className='rounded-[12px] border border-border overflow-hidden'>
      <img
        src={photo.original_url}
        alt={`Foto del paquete para ${pkg.unit?.full_identifier ?? pkg.unit?.number ?? ''}`}
        className='h-56 w-full object-contain bg-[var(--surface-2)]'
        loading='lazy'
      />
      <p className='px-3 py-1.5 text-[11.5px] text-muted-foreground'>
        Foto tomada al recibir el paquete
      </p>
    </div>
  )
}

// ─── Pickup evidence section ──────────────────────────────────────────────────

function PickupEvidenceSection({ pkg }: { pkg: IPackage }) {
  const items = pkg.media?.filter((m) => m.collection_name === 'pickup_evidence') ?? []
  if (items.length === 0) return null

  return (
    <div className='rounded-[12px] border border-border p-5 space-y-3'>
      <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
        Evidencia de entrega
      </h2>
      <div className='space-y-3'>
        {items.map((item) =>
          item.mime_type === 'application/pdf' ? (
            <a
              key={item.id}
              href={item.original_url}
              target='_blank'
              rel='noopener noreferrer'
              className='flex items-center gap-2 text-[13px] text-blue-600 underline underline-offset-2 hover:text-blue-800'
            >
              {item.file_name ?? 'Documento de evidencia'}
            </a>
          ) : (
            <div key={item.id} className='rounded-[12px] border border-border overflow-hidden'>
              <img
                src={item.original_url}
                alt='Evidencia de entrega'
                className='h-56 w-full object-contain bg-[var(--surface-2)]'
                loading='lazy'
              />
            </div>
          )
        )}
      </div>
    </div>
  )
}

// ─── Lifecycle section ────────────────────────────────────────────────────────

function LifecycleSection({ pkg }: { pkg: IPackage }) {
  return (
    <div className='space-y-2'>
      <DetailField
        label='Recibido el'
        value={pkg.received_at ? displayFormatDate(pkg.received_at) : null}
      />
      <DetailField
        label='Recibido por'
        value={pkg.received_by_user?.name ?? 'Personal de portería'}
      />
      <DetailField
        label='Notificado el'
        value={pkg.notified_at ? displayFormatDate(pkg.notified_at) : null}
      />
      <DetailField
        label='Recordatorio'
        value={pkg.reminded_at ? displayFormatDate(pkg.reminded_at) : null}
      />
      {pkg.picked_up_at && (
        <>
          <Separator />
          <DetailField label='Retirado el' value={displayFormatDate(pkg.picked_up_at)} />
          <DetailField
            label='Retirado por'
            value={pkg.picked_up_by_user?.name ?? 'Personal de portería'}
          />
          {pkg.pickup_proxy_name && (
            <DetailField
              label='Tercero'
              value={`${pkg.pickup_proxy_name}${pkg.pickup_proxy_relationship ? ` (${pkg.pickup_proxy_relationship})` : ''}`}
            />
          )}
        </>
      )}
      {pkg.incident_reason && (
        <>
          <Separator />
          <DetailField label='Motivo de incidente' value={pkg.incident_reason} />
        </>
      )}
    </div>
  )
}

// ─── Page actions ─────────────────────────────────────────────────────────────

interface PackageActionsBarProps {
  pkg: IPackage
  onActionSuccess: () => void
}

function PackageActionsBar({ pkg, onActionSuccess }: PackageActionsBarProps) {
  const [pickupOpen, setPickupOpen] = useState(false)
  const isReady = pkg.package_status_id === PACKAGE_STATUS_READY

  if (!isReady) return null

  return (
    <PermissionGuard permission='update.package'>
      <div className='flex flex-wrap items-center gap-2'>
        <Button size='sm' onClick={() => setPickupOpen(true)} className='flex items-center gap-1.5'>
          <ScanLine className='size-4' />
          Entregar paquete
        </Button>
        <PackageRowActions pkg={pkg} onSuccess={onActionSuccess} />
        <PackagePickupModal
          isOpen={pickupOpen}
          initialPackage={pkg}
          onClose={() => setPickupOpen(false)}
          onHandedOver={() => {
            setPickupOpen(false)
            onActionSuccess()
          }}
        />
      </div>
    </PermissionGuard>
  )
}

// ─── Main page ────────────────────────────────────────────────────────────────

export function PackageDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const {
    entity: pkg,
    isLoading,
    isError,
    notFound,
    refetch,
  } = useEntityDetail<IPackage>({
    queryKey: ['package', id],
    queryFn: () =>
      PackageResource.show(id!, {
        include: 'unit,status,carrier,type,recipient_user,received_by_user,picked_up_by_user,media',
      } as Record<string, unknown>),
    enabled: Boolean(id),
  })

  // Show a toast when the query fails — but not on a 404 (the EntityNotFound state already
  // communicates it). TanStack Query v5: onError removed from useQuery options.
  useEffect(() => {
    if (isError && !notFound) toast.error('No se pudo cargar el detalle del paquete')
  }, [isError, notFound])

  const handleActionSuccess = () => {
    refetch()
  }

  return (
    <div className='space-y-6'>
      {/* Back nav */}
      <div>
        <Button
          variant='ghost'
          size='sm'
          className='-ml-2 flex items-center gap-1.5 text-muted-foreground'
          onClick={() => navigate(-1)}
        >
          <ArrowLeft className='size-4' />
          Volver
        </Button>
      </div>

      <Skeleton name='package-detail' loading={isLoading}>
        {isError || !pkg ? (
          <EntityNotFound
            variant={notFound ? 'notFound' : 'error'}
            title={notFound ? 'Paquete no encontrado' : undefined}
            backTo='/package'
            backLabel='Volver a paquetes'
            onRetry={notFound ? undefined : refetch}
          />
        ) : (
          <div className='space-y-6'>
            {/* Header */}
            <div className='flex flex-wrap items-start justify-between gap-4'>
              <div>
                <PageHeader
                  title={pkg.unit?.full_identifier ?? pkg.unit?.number ?? 'Paquete'}
                  description={`Registrado ${pkg.received_at ? displayFormatDate(pkg.received_at) : '—'}`}
                />
                <div className='mt-2'>
                  <PackageStatusBadge
                    statusKey={pkg.status?.key ?? null}
                    statusName={pkg.status?.name ?? null}
                    statusColor={pkg.status?.color ?? null}
                  />
                </div>
              </div>
              <PackageActionsBar pkg={pkg} onActionSuccess={handleActionSuccess} />
            </div>

            {/* Intake photo */}
            <PackagePhoto pkg={pkg} />

            {/* Pickup evidence (signature + evidence files captured at handover) */}
            <PickupEvidenceSection pkg={pkg} />

            {/* Main details */}
            <div className='rounded-[12px] border border-border p-5 space-y-3'>
              <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
                Información del paquete
              </h2>
              <dl className='space-y-2'>
                <DetailField
                  label='Destinatario'
                  value={pkg.recipient_name ?? pkg.recipient_user?.name ?? '—'}
                />
                <DetailField
                  label='Transportista'
                  value={pkg.carrier?.name ?? pkg.carrier_other ?? '—'}
                />
                <DetailField label='Tipo' value={pkg.type?.name ?? null} />
                <DetailField label='Número de rastreo' value={pkg.tracking_number ?? null} />
                <DetailField label='Ubicación de almacén' value={pkg.storage_location ?? null} />
                <DetailField label='Descripción' value={pkg.description ?? null} />
                <DetailField
                  label='Unidad'
                  value={pkg.unit?.full_identifier ?? pkg.unit?.number ?? null}
                />
              </dl>
            </div>

            {/* Lifecycle */}
            <div className='rounded-[12px] border border-border p-5 space-y-3'>
              <h2 className='text-[13px] font-semibold text-muted-foreground uppercase tracking-wide'>
                Ciclo de vida
              </h2>
              <dl>
                <LifecycleSection pkg={pkg} />
              </dl>
            </div>
          </div>
        )}
      </Skeleton>
    </div>
  )
}
