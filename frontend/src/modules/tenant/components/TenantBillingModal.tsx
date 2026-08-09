import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { ITenant } from '@/modules/tenant/schema'
import { TenantBillingPanel } from '@/modules/tenant/components/billing/TenantBillingPanel'

interface TenantBillingModalProps {
  isOpen: boolean
  tenant: ITenant | null
  onClose: () => void
}

/**
 * Pantalla del super-admin para administrar la facturación de un organización: método
 * de pago, pagos manuales, vencimiento, plan y el historial de cobros.
 *
 * Monta el panel (y dispara la lectura de datos) sólo cuando está abierto, así no
 * carga nada al renderizar la lista de tenants.
 */
export function TenantBillingModal({ isOpen, tenant, onClose }: TenantBillingModalProps) {
  if (!isOpen || !tenant) {
    return null
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='flex max-h-[calc(100vh-2rem)] flex-col gap-0 p-0 sm:max-w-3xl'>
        <DialogHeader className='shrink-0 border-b border-border px-6 py-4 text-left'>
          <DialogTitle>Facturación · {tenant.name}</DialogTitle>
          <DialogDescription>
            Administra cómo cobra el organización, registra pagos y controla su plan contratado.
          </DialogDescription>
        </DialogHeader>

        <div className='min-h-0 flex-1 overflow-y-auto px-6 py-5'>
          <TenantBillingPanel tenantId={tenant.id} />
        </div>
      </DialogContent>
    </Dialog>
  )
}
