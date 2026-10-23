import type { ITenant } from '@/modules/tenant/schema'
import { TenantBillingPanel } from '@/modules/tenant/components/billing/TenantBillingPanel'
import { Modal } from '@/shared/components/Modal'

interface TenantBillingModalProps {
  isOpen: boolean
  tenant: ITenant | null
  onClose: () => void
}

/**
 * Pantalla del super-admin para administrar la facturación de una organización: método
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
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Facturación · ${tenant.name}`}
      description='Administra cómo cobra la organización, registra pagos y controla su plan contratado.'
      size='xl'
    >
      <div className='pb-6'>
        <TenantBillingPanel tenantId={tenant.id} />
      </div>
    </Modal>
  )
}
