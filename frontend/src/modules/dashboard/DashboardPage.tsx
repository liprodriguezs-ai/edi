import { ResidentHome } from '@/modules/dashboard/components/ResidentHome'
import { PendingPackagesWidget } from '@/modules/package/components/PendingPackagesWidget'
import { TreasurerHome } from '@/modules/treasurerPanel/components/TreasurerHome'
import { usePermissions } from '@/shared/hooks/usePermissions'

/**
 * Home del dashboard — rediseñado al "Panel del Tesorero v3" (mock Kivo7).
 * El shell (sidebar verde + topbar) lo aporta DashboardLayout; aquí va el contenido.
 *
 * El onboarding NO vive acá: tiene su propia página (`/onboarding`) y el
 * OnboardingGuard redirige hacia ella mientras la configuración esté pendiente.
 */
export const DashboardPage = () => {
  // Los roles de finanzas (admin/tesorería/cobranza) ven el panel del tesorero.
  // Un residente NO tiene estos permisos: para él ese panel quedaba vacío (puros
  // "—"/"sin acceso"), así que ve un home propio con LO SUYO (estado de cuenta,
  // reservaciones, avisos).
  const canReadPayments = usePermissions('read.payment')
  const canReadAccounts = usePermissions('read.bank_account')
  const canViewFinance = canReadPayments || canReadAccounts

  return (
    <>
      {/* Self-hides when user lacks read.package or when total pending = 0 */}
      <PendingPackagesWidget />
      {canViewFinance ? <TreasurerHome /> : <ResidentHome />}
    </>
  )
}
