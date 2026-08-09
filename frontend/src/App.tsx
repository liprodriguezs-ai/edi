import { AccessDeniedPage } from '@/app/AccessDeniedPage'
import { DashboardLayout } from '@/app/layout/DashboardLayout'
import { HomeRedirect } from '@/app/router/HomeRedirect'
import { OnboardingGuard } from '@/app/router/OnboardingGuard'
import { PermissionRoute } from '@/app/router/PermissionRoute'
import { ProtectedRoute } from '@/app/router/ProtectedRoute'
import { PublicOnlyRoute } from '@/app/router/PublicOnlyRoute'
import { RouteErrorBoundary } from '@/app/router/RouteErrorBoundary'
import { RouteFallback } from '@/app/router/RouteFallback'
import { SubscriptionGuard } from '@/app/router/SubscriptionGuard'
import { AcceptInvitationForm } from '@/components/accept-invitation-form'
import { ForgotPasswordForm } from '@/components/forgot-password-form'
import { LoginForm } from '@/components/login-form'
import { ResetPasswordForm } from '@/components/reset-password-form'
import { SignupForm } from '@/components/signup-form'
import '@/index.css'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'

// Páginas lazy: cada módulo se descarga solo cuando se navega a su ruta (code-splitting)
const OnboardingPage = lazy(() =>
  import('@/modules/onboarding/OnboardingPage').then((m) => ({ default: m.OnboardingPage }))
)
const AuditListPage = lazy(() =>
  import('@/modules/audit/AuditListPage').then((m) => ({ default: m.AuditListPage }))
)
const SelectPlanPage = lazy(() =>
  import('@/modules/checkoutSubscription/SelectPlanPage').then((m) => ({
    default: m.SelectPlanPage,
  }))
)
const CompanyListPage = lazy(() =>
  import('@/modules/company/CompanyListPage').then((m) => ({ default: m.CompanyListPage }))
)
const CompanyMePage = lazy(() =>
  import('@/modules/company/me/CompanyMePage').then((m) => ({ default: m.CompanyMePage }))
)
const DashboardPage = lazy(() =>
  import('@/modules/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage }))
)
const RoleDetailPage = lazy(() =>
  import('@/modules/role/RoleDetailPage').then((m) => ({ default: m.RoleDetailPage }))
)
const RoleListPage = lazy(() =>
  import('@/modules/role/RoleListPage').then((m) => ({ default: m.RoleListPage }))
)
const BillingPackageListPage = lazy(() =>
  import('@/modules/billingPackage/BillingPackageListPage').then((m) => ({
    default: m.BillingPackageListPage,
  }))
)
const TenantListPage = lazy(() =>
  import('@/modules/tenant/TenantListPage').then((m) => ({ default: m.TenantListPage }))
)
const TenantWizardPage = lazy(() =>
  import('@/modules/tenant/TenantWizardPage').then((m) => ({ default: m.TenantWizardPage }))
)
const SubscriptionPage = lazy(() =>
  import('@/modules/user/SubscriptionPage').then((m) => ({ default: m.SubscriptionPage }))
)
const NotificationPreferencesPage = lazy(() =>
  import('@/modules/user/NotificationPreferencesPage').then((m) => ({
    default: m.NotificationPreferencesPage,
  }))
)
const UserListPage = lazy(() =>
  import('@/modules/user/UserListPage').then((m) => ({ default: m.UserListPage }))
)
// Catálogos Kivo7
// Paquetería — módulo principal + catálogos
// Incidencias — módulo principal + catálogos
// Housing
const UserProfileListPage = lazy(() =>
  import('@/modules/userProfile/UserProfileListPage').then((m) => ({
    default: m.UserProfileListPage,
  }))
)
// Tesorería
// Reportes, configuración y notificaciones
const NotificationListPage = lazy(() =>
  import('@/modules/notification/NotificationListPage').then((m) => ({
    default: m.NotificationListPage,
  }))
)
// Comunicación y accesos
// Proveedores y proyectos
// Amenidades
// Tableros contables
// Cobranza
function App() {
  return (
    <RouteErrorBoundary>
      {/* Boundary externo: cubre rutas lazy fuera del DashboardLayout (públicas, select-plan) */}
      <Suspense fallback={<RouteFallback />}>
        <Routes>
          {/* Rutas públicas (solo sin sesión) */}
          <Route element={<PublicOnlyRoute />}>
            <Route path='/login' element={<LoginForm />} />
            <Route path='/register' element={<SignupForm />} />
            <Route
              path='/forgot-password'
              element={
                <div className='flex min-h-svh flex-col items-center justify-center bg-muted p-6 md:p-10'>
                  <div className='w-full max-w-sm'>
                    <ForgotPasswordForm />
                  </div>
                </div>
              }
            />
            <Route
              path='/auth/reset-password/:token/:email'
              element={
                <div className='flex min-h-svh flex-col items-center justify-center bg-muted p-6 md:p-10'>
                  <div className='w-full max-w-sm'>
                    <ResetPasswordForm />
                  </div>
                </div>
              }
            />
          </Route>

          {/*
        Activación de invitación: ruta NEUTRA (accesible con o sin sesión). Una
        persona con cuenta existente puede llegar ya logueada para unirse a una
        nueva unidad; por eso NO va bajo PublicOnlyRoute (redirige autenticados).
      */}
          <Route
            path='/auth/accept-invitation/:token'
            element={
              <div className='flex min-h-svh flex-col items-center justify-center bg-muted p-6 md:p-10'>
                <div className='w-full max-w-sm'>
                  <AcceptInvitationForm />
                </div>
              </div>
            }
          />

          {/* Rutas protegidas (requieren sesión) */}
          <Route element={<ProtectedRoute />}>
            {/* Selección de plan: requiere auth pero NO requiere suscripción activa */}
            <Route path='/select-plan' element={<SelectPlanPage />} />

            {/* App principal: requiere auth + suscripción activa */}
            <Route element={<SubscriptionGuard />}>
              <Route element={<DashboardLayout />}>
                <Route path='/' element={<HomeRedirect />} />
                <Route
                  element={
                    <PermissionRoute
                      role={[ERoleUserSlug.ADMINISTRATOR, ERoleUserSlug.ACCOUNT_OWNER]}
                    />
                  }
                >
                  <Route path='/onboarding' element={<OnboardingPage />} />
                </Route>
                <Route path='/acceso-denegado' element={<AccessDeniedPage />} />

                <Route element={<PermissionRoute permissionEntity='dashboard' />}>
                  {/* Mientras el onboarding esté incompleto, el admin va a /onboarding */}
                  <Route element={<OnboardingGuard />}>
                    <Route path='/dashboard' element={<DashboardPage />} />
                  </Route>
                </Route>
                <Route path='/company/me' element={<CompanyMePage />} />
                <Route element={<PermissionRoute permissionEntity='company' />}>
                  <Route path='/company' element={<CompanyListPage />} />
                </Route>

                <Route element={<PermissionRoute permissionEntity='user' />}>
                  <Route path='/user' element={<UserListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='tenant' />}>
                  <Route path='/tenant' element={<TenantListPage />} />
                  <Route path='/tenant/new' element={<TenantWizardPage />} />
                </Route>

                <Route path='/subscription' element={<SubscriptionPage />} />
                <Route path='/notification-preferences' element={<NotificationPreferencesPage />} />

                <Route element={<PermissionRoute permissionEntity='billing_package' />}>
                  <Route path='/billing-package' element={<BillingPackageListPage />} />
                </Route>
                <Route element={<PermissionRoute role={[ERoleUserSlug.SUPER_ADMIN]} />}>
                  <Route path='/role' element={<RoleListPage />} />
                  <Route path='/role/:id' element={<RoleDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='user_profile' />}>
                  <Route path='/user-profile' element={<UserProfileListPage />} />
                </Route>

                <Route path='/notification' element={<NotificationListPage />} />

                <Route element={<PermissionRoute permissionEntity='audit' />}>
                  <Route path='/audit' element={<AuditListPage />} />
                </Route>
              </Route>
            </Route>
          </Route>

          {/* Fallback */}
          <Route path='*' element={<Navigate to='/' replace />} />
        </Routes>
      </Suspense>
    </RouteErrorBoundary>
  )
}

export default App