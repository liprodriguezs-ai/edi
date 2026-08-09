import { PageHeader } from '@/shared/components/atoms/PageHeader'

/**
 * Home del dashboard.
 *
 * El shell (sidebar + topbar) lo aporta DashboardLayout; aquí va el contenido.
 * El onboarding NO vive acá: tiene su propia página (`/onboarding`) y el
 * OnboardingGuard redirige hacia ella mientras la configuración esté pendiente.
 *
 * ESTADO: placeholder. La plantilla mostraba dos paneles según permisos —el del
 * tesorero (cobranza/tesorería) y el del residente (estado de cuenta, avisos,
 * reservaciones)—, ambos atados al dominio de condominios. Los paneles de EDI
 * (tamizajes del mes, resultados por semáforo, áreas afectadas, seguimientos
 * vencidos) se construyen en su propio change de SDD.
 */
export const DashboardPage = () => {
  return (
    <>
      <PageHeader title='Inicio' description='Resumen de actividad' />
    </>
  )
}
