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
// Catálogos EDI
const HealthCenterListPage = lazy(() =>
  import('@/modules/healthCenter/HealthCenterListPage').then((m) => ({
    default: m.HealthCenterListPage,
  }))
)
const PractitionerListPage = lazy(() =>
  import('@/modules/practitioner/PractitionerListPage').then((m) => ({
    default: m.PractitionerListPage,
  }))
)
const ChildListPage = lazy(() =>
  import('@/modules/child/ChildListPage').then((m) => ({ default: m.ChildListPage }))
)
const ChildFormPage = lazy(() =>
  import('@/modules/child/ChildFormPage').then((m) => ({ default: m.ChildFormPage }))
)
const ChildDetailPage = lazy(() =>
  import('@/modules/child/ChildDetailPage').then((m) => ({ default: m.ChildDetailPage }))
)
const VisitListPage = lazy(() =>
  import('@/modules/visit/VisitListPage').then((m) => ({ default: m.VisitListPage }))
)
const AgendaPage = lazy(() =>
  import('@/modules/appointment/AgendaPage').then((m) => ({ default: m.AgendaPage }))
)
const CaregiverRelationshipPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.CaregiverRelationshipPage }))
)
const EducationLevelPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.EducationLevelPage }))
)
const OccupationPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.OccupationPage }))
)
// Instrumento EDI (inspector read-only)
const InstrumentVersionListPage = lazy(() =>
  import('@/modules/instrument/InstrumentVersionListPage').then((m) => ({
    default: m.InstrumentVersionListPage,
  }))
)
const InstrumentVersionDetailPage = lazy(() =>
  import('@/modules/instrument/InstrumentVersionDetailPage').then((m) => ({
    default: m.InstrumentVersionDetailPage,
  }))
)
// Prueba EDI (edi-screening-ui)
const ScreeningListPage = lazy(() =>
  import('@/modules/screening/ScreeningListPage').then((m) => ({ default: m.ScreeningListPage }))
)
const ScreeningDetailPage = lazy(() =>
  import('@/modules/screening/ScreeningDetailPage').then((m) => ({
    default: m.ScreeningDetailPage,
  }))
)
const ScreeningApplyPage = lazy(() =>
  import('@/modules/screening/ScreeningApplyPage').then((m) => ({ default: m.ScreeningApplyPage }))
)
// Estimulación temprana (edi-stimulation-sessions)
const StimulationSessionListPage = lazy(() =>
  import('@/modules/stimulationSession/StimulationSessionListPage').then((m) => ({
    default: m.StimulationSessionListPage,
  }))
)
const StimulationSessionFormPage = lazy(() =>
  import('@/modules/stimulationSession/StimulationSessionFormPage').then((m) => ({
    default: m.StimulationSessionFormPage,
  }))
)
const StimulationGroupListPage = lazy(() =>
  import('@/modules/stimulationGroup/StimulationGroupListPage').then((m) => ({
    default: m.StimulationGroupListPage,
  }))
)
const StimulationGroupDetailPage = lazy(() =>
  import('@/modules/stimulationGroup/StimulationGroupDetailPage').then((m) => ({
    default: m.StimulationGroupDetailPage,
  }))
)
const AttendanceSheetPage = lazy(() =>
  import('@/modules/stimulationGroup/AttendanceSheetPage').then((m) => ({
    default: m.AttendanceSheetPage,
  }))
)
const StimulationDynamicPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.StimulationDynamicPage }))
)
const BodyZonePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.BodyZonePage }))
)
const MassageMovementPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.MassageMovementPage }))
)
const DidacticMaterialPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.DidacticMaterialPage }))
)
const SongPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.SongPage }))
)
const StimulationGroupTypePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.StimulationGroupTypePage }))
)
// Seguimientos (edi-followup-and-referral)
const FollowUpListPage = lazy(() =>
  import('@/modules/followUp/FollowUpListPage').then((m) => ({ default: m.FollowUpListPage }))
)
const FollowUpDetailPage = lazy(() =>
  import('@/modules/followUp/FollowUpDetailPage').then((m) => ({ default: m.FollowUpDetailPage }))
)
const ReferralSpecialtyPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.ReferralSpecialtyPage }))
)
// Reportes e indicadores (edi-reports-and-indicators)
const IndicatorDashboardPage = lazy(() =>
  import('@/modules/reports/IndicatorDashboardPage').then((m) => ({
    default: m.IndicatorDashboardPage,
  }))
)
const NominalListPage = lazy(() =>
  import('@/modules/reports/NominalListPage').then((m) => ({ default: m.NominalListPage }))
)
// Concentrado EDI y Reporte general (edi-legacy-parity, S4)
const EdiConcentratePage = lazy(() =>
  import('@/modules/reports/EdiConcentratePage').then((m) => ({ default: m.EdiConcentratePage }))
)
const GeneralReportPage = lazy(() =>
  import('@/modules/reports/GeneralReportPage').then((m) => ({ default: m.GeneralReportPage }))
)
// Edad corregida (edi-legacy-parity, S6)
const CorrectedAgePage = lazy(() =>
  import('@/modules/corrected-age/CorrectedAgePage').then((m) => ({ default: m.CorrectedAgePage }))
)
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

                {/* Clinic + ChildRecord (edi-clinic-and-child-registry) */}
                <Route element={<PermissionRoute permissionEntity='health_center' />}>
                  <Route path='/health-center' element={<HealthCenterListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='practitioner_profile' />}>
                  <Route path='/practitioner' element={<PractitionerListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='child' />}>
                  <Route path='/child' element={<ChildListPage />} />
                  <Route path='/child/new' element={<ChildFormPage />} />
                  <Route path='/child/:id' element={<ChildDetailPage />} />
                  <Route path='/child/:id/edit' element={<ChildFormPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='visit' />}>
                  <Route path='/visit' element={<VisitListPage />} />
                </Route>
                {/* Agenda (odd/tasks/edi-agenda.md) */}
                <Route element={<PermissionRoute permissionEntity='appointment' />}>
                  <Route path='/agenda' element={<AgendaPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='caregiver_relationship' />}>
                  <Route path='/caregiver-relationship' element={<CaregiverRelationshipPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='education_level' />}>
                  <Route path='/education-level' element={<EducationLevelPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='occupation' />}>
                  <Route path='/occupation' element={<OccupationPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='instrument' />}>
                  <Route path='/instrument' element={<InstrumentVersionListPage />} />
                  <Route path='/instrument/:id' element={<InstrumentVersionDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='screening' />}>
                  <Route path='/screening' element={<ScreeningListPage />} />
                  <Route path='/screening/:id' element={<ScreeningDetailPage />} />
                  <Route path='/screening/apply/:visitId' element={<ScreeningApplyPage />} />
                  <Route path='/screening/:id/apply' element={<ScreeningApplyPage />} />
                </Route>

                {/* Estimulación temprana (edi-stimulation-sessions) */}
                <Route element={<PermissionRoute permissionEntity='stimulation_session' />}>
                  <Route path='/stimulation-session' element={<StimulationSessionListPage />} />
                  <Route path='/stimulation-session/new' element={<StimulationSessionFormPage />} />
                  <Route
                    path='/stimulation-session/:id/edit'
                    element={<StimulationSessionFormPage />}
                  />
                </Route>
                <Route element={<PermissionRoute permissionEntity='stimulation_group' />}>
                  <Route path='/stimulation-group' element={<StimulationGroupListPage />} />
                  <Route path='/stimulation-group/:id' element={<StimulationGroupDetailPage />} />
                  <Route
                    path='/stimulation-group/:id/attendance'
                    element={<AttendanceSheetPage />}
                  />
                </Route>
                <Route element={<PermissionRoute permissionEntity='stimulation_dynamic' />}>
                  <Route path='/stimulation-dynamic' element={<StimulationDynamicPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='body_zone' />}>
                  <Route path='/body-zone' element={<BodyZonePage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='massage_movement' />}>
                  <Route path='/massage-movement' element={<MassageMovementPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='didactic_material' />}>
                  <Route path='/didactic-material' element={<DidacticMaterialPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='song' />}>
                  <Route path='/song' element={<SongPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='stimulation_group_type' />}>
                  <Route path='/stimulation-group-type' element={<StimulationGroupTypePage />} />
                </Route>

                {/* Seguimientos (edi-followup-and-referral) */}
                <Route element={<PermissionRoute permissionEntity='follow_up' />}>
                  <Route path='/follow-up' element={<FollowUpListPage />} />
                  <Route path='/follow-up/:id' element={<FollowUpDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='referral_specialty' />}>
                  <Route path='/referral-specialty' element={<ReferralSpecialtyPage />} />
                </Route>

                {/* Reportes e indicadores (edi-reports-and-indicators) */}
                <Route element={<PermissionRoute permissionEntity='screening_indicator' />}>
                  <Route path='/reports/indicators' element={<IndicatorDashboardPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='nominal_report' />}>
                  <Route path='/reports/nominal' element={<NominalListPage />} />
                </Route>

                {/* Concentrado EDI y Reporte general (edi-legacy-parity, S4) */}
                <Route element={<PermissionRoute permissionEntity='edi_concentrate' />}>
                  <Route path='/reports/edi-concentrate' element={<EdiConcentratePage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='general_report' />}>
                  <Route path='/reports/general' element={<GeneralReportPage />} />
                </Route>

                {/* Edad corregida (edi-legacy-parity, S6) */}
                <Route element={<PermissionRoute permissionEntity='corrected_age' />}>
                  <Route path='/corrected-age' element={<CorrectedAgePage />} />
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
