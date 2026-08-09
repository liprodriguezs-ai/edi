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
const ImportPage = lazy(() =>
  import('@/modules/import/ImportPage').then((m) => ({ default: m.ImportPage }))
)
const SelectPlanPage = lazy(() =>
  import('@/modules/checkoutSubscription/SelectPlanPage').then((m) => ({
    default: m.SelectPlanPage,
  }))
)
const CollaboratorDetailPage = lazy(() =>
  import('@/modules/collaborator/CollaboratorDetailPage').then((m) => ({
    default: m.CollaboratorDetailPage,
  }))
)
const CollaboratorListPage = lazy(() =>
  import('@/modules/collaborator/CollaboratorListPage').then((m) => ({
    default: m.CollaboratorListPage,
  }))
)
const CollaboratorImportPage = lazy(() =>
  import('@/modules/collaborator/CollaboratorImportPage').then((m) => ({
    default: m.CollaboratorImportPage,
  }))
)
const CollaboratorMePage = lazy(() =>
  import('@/modules/collaborator/me/CollaboratorMePage').then((m) => ({
    default: m.CollaboratorMePage,
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
const TowerListPage = lazy(() =>
  import('@/modules/tower/TowerListPage').then((m) => ({ default: m.TowerListPage }))
)
const SectionListPage = lazy(() =>
  import('@/modules/section/SectionListPage').then((m) => ({ default: m.SectionListPage }))
)
const UnitFeatureListPage = lazy(() =>
  import('@/modules/unitFeature/UnitFeatureListPage').then((m) => ({
    default: m.UnitFeatureListPage,
  }))
)
const UnitTypeListPage = lazy(() =>
  import('@/modules/unitType/UnitTypeListPage').then((m) => ({ default: m.UnitTypeListPage }))
)
const UnitStatusListPage = lazy(() =>
  import('@/modules/unitStatus/UnitStatusListPage').then((m) => ({ default: m.UnitStatusListPage }))
)
const EmployeePositionPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.EmployeePositionPage }))
)
const PayFrequencyPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.PayFrequencyPage }))
)
const EmployeeShiftPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.EmployeeShiftPage }))
)
const BankAccountPurposePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.BankAccountPurposePage }))
)
const ChargeConceptCategoryPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.ChargeConceptCategoryPage }))
)
const CommunityProjectCategoryPage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.CommunityProjectCategoryPage }))
)
const ProviderTypePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.ProviderTypePage }))
)
const ProviderServiceTypePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.ProviderServiceTypePage }))
)
const VisitorMotivePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.VisitorMotivePage }))
)
const AnnouncementTypePage = lazy(() =>
  import('@/modules/catalogs/pages').then((m) => ({ default: m.AnnouncementTypePage }))
)
// Paquetería — módulo principal + catálogos
const PackageListPage = lazy(() =>
  import('@/modules/package/PackageListPage').then((m) => ({ default: m.PackageListPage }))
)
const PackageDetailPage = lazy(() =>
  import('@/modules/package/PackageDetailPage').then((m) => ({ default: m.PackageDetailPage }))
)
const MyPackagesPage = lazy(() =>
  import('@/modules/package/MyPackagesPage').then((m) => ({ default: m.MyPackagesPage }))
)
const PackageCarrierListPage = lazy(() =>
  import('@/modules/package/carrier/PackageCarrierListPage').then((m) => ({
    default: m.PackageCarrierListPage,
  }))
)
const PackageTypeListPage = lazy(() =>
  import('@/modules/package/type/PackageTypeListPage').then((m) => ({
    default: m.PackageTypeListPage,
  }))
)
// Incidencias — módulo principal + catálogos
const ComplaintListPage = lazy(() =>
  import('@/modules/complaint/ComplaintListPage').then((m) => ({ default: m.ComplaintListPage }))
)
const ComplaintDetailPage = lazy(() =>
  import('@/modules/complaint/ComplaintDetailPage').then((m) => ({
    default: m.ComplaintDetailPage,
  }))
)
const ComplaintCategoryListPage = lazy(() =>
  import('@/modules/complaint/category/ComplaintCategoryListPage').then((m) => ({
    default: m.ComplaintCategoryListPage,
  }))
)
const ComplaintPriorityListPage = lazy(() =>
  import('@/modules/complaint/priority/ComplaintPriorityListPage').then((m) => ({
    default: m.ComplaintPriorityListPage,
  }))
)
const UnitRelationshipListPage = lazy(() =>
  import('@/modules/unitRelationship/UnitRelationshipListPage').then((m) => ({
    default: m.UnitRelationshipListPage,
  }))
)
const ExpenseCategoryListPage = lazy(() =>
  import('@/modules/expenseCategory/ExpenseCategoryListPage').then((m) => ({
    default: m.ExpenseCategoryListPage,
  }))
)
// Housing
const UnitListPage = lazy(() =>
  import('@/modules/unit/UnitListPage').then((m) => ({ default: m.UnitListPage }))
)
const UnitDetailPage = lazy(() =>
  import('@/modules/unit/UnitDetailPage').then((m) => ({ default: m.UnitDetailPage }))
)
const MyUnitPage = lazy(() =>
  import('@/modules/unit/MyUnitPage').then((m) => ({ default: m.MyUnitPage }))
)
const MyAccessLogPage = lazy(() =>
  import('@/modules/access/MyAccessLogPage').then((m) => ({ default: m.MyAccessLogPage }))
)
const MyQrPage = lazy(() =>
  import('@/modules/access/MyQrPage').then((m) => ({ default: m.MyQrPage }))
)
const ClusterDocumentsPage = lazy(() =>
  import('@/modules/configuration/ClusterDocumentsPage').then((m) => ({
    default: m.ClusterDocumentsPage,
  }))
)
const UnitUserListPage = lazy(() =>
  import('@/modules/unitUser/UnitUserListPage').then((m) => ({ default: m.UnitUserListPage }))
)
const VehicleListPage = lazy(() =>
  import('@/modules/vehicle/VehicleListPage').then((m) => ({ default: m.VehicleListPage }))
)
const UserProfileListPage = lazy(() =>
  import('@/modules/userProfile/UserProfileListPage').then((m) => ({
    default: m.UserProfileListPage,
  }))
)
const BoardPeriodListPage = lazy(() =>
  import('@/modules/boardPeriod/BoardPeriodListPage').then((m) => ({
    default: m.BoardPeriodListPage,
  }))
)
// Tesorería
const BankAccountListPage = lazy(() =>
  import('@/modules/bankAccount/BankAccountListPage').then((m) => ({
    default: m.BankAccountListPage,
  }))
)
const ExpenseListPage = lazy(() =>
  import('@/modules/expense/ExpenseListPage').then((m) => ({ default: m.ExpenseListPage }))
)
// Reportes, configuración y notificaciones
const ReportsPage = lazy(() =>
  import('@/modules/reports/ReportsPage').then((m) => ({ default: m.ReportsPage }))
)
const ConfigurationPage = lazy(() =>
  import('@/modules/configuration/ConfigurationPage').then((m) => ({
    default: m.ConfigurationPage,
  }))
)
const NotificationListPage = lazy(() =>
  import('@/modules/notification/NotificationListPage').then((m) => ({
    default: m.NotificationListPage,
  }))
)
// Comunicación y accesos
const AnnouncementsPage = lazy(() =>
  import('@/modules/announcement/AnnouncementsPage').then((m) => ({ default: m.AnnouncementsPage }))
)
const AnnouncementEditorPage = lazy(() =>
  import('@/modules/announcement/AnnouncementEditorPage').then((m) => ({
    default: m.AnnouncementEditorPage,
  }))
)
const AnnouncementViewPage = lazy(() =>
  import('@/modules/announcement/AnnouncementViewPage').then((m) => ({
    default: m.AnnouncementViewPage,
  }))
)
const ChatPage = lazy(() =>
  import('@/modules/chat/ChatPage').then((m) => ({ default: m.ChatPage }))
)
const ChannelEditorPage = lazy(() =>
  import('@/modules/chat/ChannelEditorPage').then((m) => ({ default: m.ChannelEditorPage }))
)
const AccessNowPage = lazy(() =>
  import('@/modules/access/AccessNowPage').then((m) => ({ default: m.AccessNowPage }))
)
const VisitorListPage = lazy(() =>
  import('@/modules/access/VisitorListPage').then((m) => ({ default: m.VisitorListPage }))
)
const AccessLogListPage = lazy(() =>
  import('@/modules/access/AccessLogListPage').then((m) => ({ default: m.AccessLogListPage }))
)
const BlacklistListPage = lazy(() =>
  import('@/modules/access/BlacklistListPage').then((m) => ({ default: m.BlacklistListPage }))
)
// Proveedores y proyectos
const ProviderListPage = lazy(() =>
  import('@/modules/provider/ProviderListPage').then((m) => ({ default: m.ProviderListPage }))
)
const CommunityProjectListPage = lazy(() =>
  import('@/modules/communityProject/CommunityProjectListPage').then((m) => ({
    default: m.CommunityProjectListPage,
  }))
)
const CommunityProjectDetailPage = lazy(() =>
  import('@/modules/communityProject/CommunityProjectDetailPage').then((m) => ({
    default: m.CommunityProjectDetailPage,
  }))
)
const ProjectContributionListPage = lazy(() =>
  import('@/modules/communityProject/ProjectContributionListPage').then((m) => ({
    default: m.ProjectContributionListPage,
  }))
)
// Amenidades
const AmenityListPage = lazy(() =>
  import('@/modules/amenity/AmenityListPage').then((m) => ({ default: m.AmenityListPage }))
)
const AmenityFormPage = lazy(() =>
  import('@/modules/amenity/AmenityFormPage').then((m) => ({ default: m.AmenityFormPage }))
)
const AmenityCatalogPage = lazy(() =>
  import('@/modules/amenity/AmenityCatalogPage').then((m) => ({ default: m.AmenityCatalogPage }))
)
const AmenityBrowseDetailPage = lazy(() =>
  import('@/modules/amenity/AmenityBrowseDetailPage').then((m) => ({
    default: m.AmenityBrowseDetailPage,
  }))
)
const AmenityReservationListPage = lazy(() =>
  import('@/modules/reservation/AmenityReservationListPage').then((m) => ({
    default: m.AmenityReservationListPage,
  }))
)
const AmenityReservationDetailPage = lazy(() =>
  import('@/modules/reservation/AmenityReservationDetailPage').then((m) => ({
    default: m.AmenityReservationDetailPage,
  }))
)
const AmenityReservationCalendarPage = lazy(() =>
  import('@/modules/reservation/AmenityReservationCalendarPage').then((m) => ({
    default: m.AmenityReservationCalendarPage,
  }))
)
const AmenityOccupancyCalendarPage = lazy(() =>
  import('@/modules/reservation/AmenityOccupancyCalendarPage').then((m) => ({
    default: m.AmenityOccupancyCalendarPage,
  }))
)
const MyReservationsPage = lazy(() =>
  import('@/modules/reservation/MyReservationsPage').then((m) => ({
    default: m.MyReservationsPage,
  }))
)
// Tableros contables
const ResultadosDashboard = lazy(() =>
  import('@/modules/dashboards/resultados/ResultadosDashboard').then((m) => ({
    default: m.ResultadosDashboard,
  }))
)
const CobranzaDashboard = lazy(() =>
  import('@/modules/dashboards/cobranza/CobranzaDashboard').then((m) => ({
    default: m.CobranzaDashboard,
  }))
)
const TesoreriaDashboard = lazy(() =>
  import('@/modules/dashboards/tesoreria/TesoreriaDashboard').then((m) => ({
    default: m.TesoreriaDashboard,
  }))
)
const GastosDashboard = lazy(() =>
  import('@/modules/dashboards/gastos/GastosDashboard').then((m) => ({
    default: m.GastosDashboard,
  }))
)
const ProyectosDashboard = lazy(() =>
  import('@/modules/dashboards/proyectos/ProyectosDashboard').then((m) => ({
    default: m.ProyectosDashboard,
  }))
)
// Cobranza
const ChargeConceptListPage = lazy(() =>
  import('@/modules/chargeConcept/ChargeConceptListPage').then((m) => ({
    default: m.ChargeConceptListPage,
  }))
)
const ChargePolicyListPage = lazy(() =>
  import('@/modules/chargePolicy/ChargePolicyListPage').then((m) => ({
    default: m.ChargePolicyListPage,
  }))
)
const ChargePolicyAllListPage = lazy(() =>
  import('@/modules/chargePolicy/ChargePolicyAllListPage').then((m) => ({
    default: m.ChargePolicyAllListPage,
  }))
)
const ChargeRuleFormPage = lazy(() =>
  import('@/modules/chargePolicy/ChargeRuleFormPage').then((m) => ({
    default: m.ChargeRuleFormPage,
  }))
)
const ChargeBatchListPage = lazy(() =>
  import('@/modules/chargeBatch/ChargeBatchListPage').then((m) => ({
    default: m.ChargeBatchListPage,
  }))
)
const ChargeBatchFormPage = lazy(() =>
  import('@/modules/chargeBatch/ChargeBatchFormPage').then((m) => ({
    default: m.ChargeBatchFormPage,
  }))
)
const ChargeBatchDetailPage = lazy(() =>
  import('@/modules/chargeBatch/ChargeBatchDetailPage').then((m) => ({
    default: m.ChargeBatchDetailPage,
  }))
)
const ChargeListPage = lazy(() =>
  import('@/modules/charge/ChargeListPage').then((m) => ({ default: m.ChargeListPage }))
)
const PaymentListPage = lazy(() =>
  import('@/modules/payment/PaymentListPage').then((m) => ({ default: m.PaymentListPage }))
)
const PaymentDetailPage = lazy(() =>
  import('@/modules/payment/PaymentDetailPage').then((m) => ({ default: m.PaymentDetailPage }))
)
const StatementPage = lazy(() =>
  import('@/modules/payment/StatementPage').then((m) => ({ default: m.StatementPage }))
)
const DelinquencyPage = lazy(() =>
  import('@/modules/payment/DelinquencyPage').then((m) => ({ default: m.DelinquencyPage }))
)

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

                {/* Housing */}
                <Route element={<PermissionRoute permissionEntity='unit' />}>
                  <Route path='/unit' element={<UnitListPage />} />
                  <Route path='/unit/:id' element={<UnitDetailPage />} />
                  <Route path='/my-unit' element={<MyUnitPage />} />
                  <Route path='/my-access-log' element={<MyAccessLogPage />} />
                  <Route path='/my-qr' element={<MyQrPage />} />
                  <Route path='/cluster-documents' element={<ClusterDocumentsPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='unit_user' />}>
                  <Route path='/unit-user' element={<UnitUserListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='vehicle' />}>
                  <Route path='/vehicle' element={<VehicleListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='user_profile' />}>
                  <Route path='/user-profile' element={<UserProfileListPage />} />
                </Route>

                {/* Mesa directiva */}
                <Route element={<PermissionRoute permissionEntity='board_period' />}>
                  <Route path='/board-period' element={<BoardPeriodListPage />} />
                </Route>

                {/* Tesorería */}
                <Route element={<PermissionRoute permissionEntity='bank_account' />}>
                  <Route path='/bank-account' element={<BankAccountListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='expense' />}>
                  <Route path='/expense' element={<ExpenseListPage />} />
                </Route>

                {/* Tableros contables */}
                <Route element={<PermissionRoute permissionEntity='report_pnl' />}>
                  <Route path='/tableros/resultados' element={<ResultadosDashboard />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='report_collections' />}>
                  <Route path='/tableros/cobranza' element={<CobranzaDashboard />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='report_treasury' />}>
                  <Route path='/tableros/tesoreria' element={<TesoreriaDashboard />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='report_expenses' />}>
                  <Route path='/tableros/gastos' element={<GastosDashboard />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='report_projects' />}>
                  <Route path='/tableros/proyectos' element={<ProyectosDashboard />} />
                </Route>

                {/* Reportes y configuración */}
                <Route element={<PermissionRoute permissionEntity='report' />}>
                  <Route path='/reports' element={<ReportsPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='configuration' />}>
                  <Route path='/configuration' element={<ConfigurationPage />} />
                </Route>
                <Route path='/notification' element={<NotificationListPage />} />

                {/* Comunicación */}
                <Route element={<PermissionRoute permissionEntity='announcement' />}>
                  <Route path='/announcement' element={<AnnouncementsPage />} />
                  <Route path='/announcement/:id' element={<AnnouncementViewPage />} />
                  {/* El editor exige permiso de ESCRITURA (crear o editar): la lectura de
                      announcement sola no debe permitir deep-linkear al alta/edición. */}
                  <Route
                    element={
                      <PermissionRoute
                        permission={['create.announcement', 'update.announcement']}
                      />
                    }
                  >
                    <Route path='/announcement/new' element={<AnnouncementEditorPage />} />
                    <Route path='/announcement/:id/edit' element={<AnnouncementEditorPage />} />
                  </Route>
                </Route>
                <Route element={<PermissionRoute permissionEntity='chat_channel' />}>
                  <Route path='/chat-channel' element={<ChatPage />} />
                  {/* El editor exige permiso de ESCRITURA (crear o editar): la lectura de
                      chat_channel sola no debe permitir deep-linkear al alta/edición. */}
                  <Route
                    element={
                      <PermissionRoute
                        permission={['create.chat_channel', 'update.chat_channel']}
                      />
                    }
                  >
                    <Route path='/chat-channel/new' element={<ChannelEditorPage />} />
                    <Route path='/chat-channel/:id/edit' element={<ChannelEditorPage />} />
                  </Route>
                </Route>

                {/* Accesos */}
                <Route element={<PermissionRoute permissionEntity='access_log' />}>
                  <Route path='/access' element={<AccessNowPage />} />
                  <Route path='/access-log' element={<AccessLogListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='visitor' />}>
                  <Route path='/visitor' element={<VisitorListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='blacklist_entry' />}>
                  <Route path='/blacklist' element={<BlacklistListPage />} />
                </Route>

                {/* Proveedores y proyectos */}
                <Route element={<PermissionRoute permissionEntity='provider' />}>
                  <Route path='/provider' element={<ProviderListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='community_project' />}>
                  <Route path='/community-project' element={<CommunityProjectListPage />} />
                  <Route path='/community-project/:id' element={<CommunityProjectDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permission='update.project_contribution' />}>
                  <Route path='/project-contribution' element={<ProjectContributionListPage />} />
                </Route>

                {/* Amenidades — el menú/toggle de tenant llega en S17; las rutas admin ya
                    quedan listas detrás del permiso 'amenity'. */}
                <Route element={<PermissionRoute permissionEntity='amenity' />}>
                  <Route path='/amenity' element={<AmenityListPage />} />
                  <Route path='/amenity/new' element={<AmenityFormPage />} />
                  <Route path='/amenity/:id/edit' element={<AmenityFormPage />} />
                  {/* Catálogo/detalle del residente (S14) — mismo permiso ('read.amenity'),
                      rutas propias ('/amenities', plural) para no chocar con el CRUD admin. */}
                  <Route path='/amenities' element={<AmenityCatalogPage />} />
                  <Route path='/amenities/:id' element={<AmenityBrowseDetailPage />} />
                </Route>
                {/* Reservaciones de amenidades (S13a — admin list/detail/acciones;
                    S13b — calendario schedule-x coloreado/filtrable por amenidad;
                    S16 — "Mis reservaciones" del residente; S18 — calendario de
                    ocupación anonimizado del comité de vigilancia). */}
                <Route element={<PermissionRoute permissionEntity='amenity_reservation' />}>
                  <Route path='/amenity-reservation' element={<AmenityReservationListPage />} />
                  <Route
                    path='/amenity-reservation/calendar'
                    element={<AmenityReservationCalendarPage />}
                  />
                  <Route
                    path='/amenity-reservation/:id'
                    element={<AmenityReservationDetailPage />}
                  />
                  <Route path='/my-reservations' element={<MyReservationsPage />} />
                  <Route path='/amenity-occupancy' element={<AmenityOccupancyCalendarPage />} />
                </Route>

                {/* Cobranza */}
                <Route element={<PermissionRoute permissionEntity='charge_concept' />}>
                  <Route path='/charge-concept' element={<ChargeConceptListPage />} />
                </Route>
                {/* Reglas de cobro (charge_policy): viven dentro de un concepto (1:N).
                Listado + COCKPIT unificado (config + cobros generados en una pantalla). */}
                {/* Alta global de regla: el concepto se elige como 1ª sección del form.
                Requiere create.charge_policy (la ruta estática gana sobre /:id). */}
                <Route element={<PermissionRoute permission='create.charge_policy' />}>
                  <Route path='/charge-policy/new' element={<ChargeRuleFormPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='charge_policy' />}>
                  <Route path='/charge-policy' element={<ChargePolicyAllListPage />} />
                  <Route
                    path='/charge-concept/:conceptId/policies'
                    element={<ChargePolicyListPage />}
                  />
                  <Route
                    path='/charge-concept/:conceptId/policy/new'
                    element={<ChargeRuleFormPage />}
                  />
                  <Route path='/charge-policy/:id' element={<ChargeRuleFormPage />} />
                  <Route path='/charge-policy/:id/edit' element={<ChargeRuleFormPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='charge_batch' />}>
                  <Route path='/charge-batch' element={<ChargeBatchListPage />} />
                  <Route path='/charge-batch/new' element={<ChargeBatchFormPage />} />
                  <Route path='/charge-batch/:id' element={<ChargeBatchDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='charge' />}>
                  <Route path='/charge' element={<ChargeListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='payment' />}>
                  <Route path='/payment' element={<PaymentListPage />} />
                  <Route path='/delinquency' element={<DelinquencyPage />} />
                </Route>
                {/* Estado de cuenta + detalle de pago: el residente ve los SUYOS (gated
                por unidad propia en el backend); el guard usa payment_application,
                no payment, para que coincida con el menú y con lo que el residente
                tiene. /payment/:id es el destino de los deep-links de cobranza. */}
                <Route element={<PermissionRoute permissionEntity='payment_application' />}>
                  <Route path='/statement' element={<StatementPage />} />
                  <Route path='/payment/:id' element={<PaymentDetailPage />} />
                </Route>

                {/* Catálogos Kivo7 */}
                <Route element={<PermissionRoute permissionEntity='tower' />}>
                  <Route path='/tower' element={<TowerListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='section' />}>
                  <Route path='/section' element={<SectionListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='unit_feature' />}>
                  <Route path='/unit-feature' element={<UnitFeatureListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='unit_type' />}>
                  <Route path='/unit-type' element={<UnitTypeListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='unit_status' />}>
                  <Route path='/unit-status' element={<UnitStatusListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='unit_relationship' />}>
                  <Route path='/unit-relationship' element={<UnitRelationshipListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='expense_category' />}>
                  <Route path='/expense-category' element={<ExpenseCategoryListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='employee_position' />}>
                  <Route path='/employee-position' element={<EmployeePositionPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='pay_frequency' />}>
                  <Route path='/pay-frequency' element={<PayFrequencyPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='employee_shift' />}>
                  <Route path='/employee-shift' element={<EmployeeShiftPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='bank_account_purpose' />}>
                  <Route path='/bank-account-purpose' element={<BankAccountPurposePage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='charge_concept_category' />}>
                  <Route path='/charge-concept-category' element={<ChargeConceptCategoryPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='community_project_category' />}>
                  <Route
                    path='/community-project-category'
                    element={<CommunityProjectCategoryPage />}
                  />
                </Route>
                <Route element={<PermissionRoute permissionEntity='provider_type' />}>
                  <Route path='/provider-type' element={<ProviderTypePage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='provider_service_type' />}>
                  <Route path='/provider-service-type' element={<ProviderServiceTypePage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='visitor_motive' />}>
                  <Route path='/visitor-motive' element={<VisitorMotivePage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='announcement_type' />}>
                  <Route path='/announcement-type' element={<AnnouncementTypePage />} />
                </Route>
                {/* Paquetería */}
                <Route element={<PermissionRoute permissionEntity='package' />}>
                  <Route path='/package' element={<PackageListPage />} />
                  <Route path='/package/:id' element={<PackageDetailPage />} />
                </Route>
                {/* Mis paquetes: visible para residentes con read.package (gated por unidad en backend) */}
                <Route element={<PermissionRoute permissionEntity='package' />}>
                  <Route path='/my-packages' element={<MyPackagesPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='package_carrier' />}>
                  <Route path='/package-carrier' element={<PackageCarrierListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='package_type' />}>
                  <Route path='/package-type' element={<PackageTypeListPage />} />
                </Route>
                {/* Incidencias */}
                <Route element={<PermissionRoute permissionEntity='complaint' />}>
                  <Route path='/complaint' element={<ComplaintListPage />} />
                  <Route path='/complaint/:id' element={<ComplaintDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='complaint_category' />}>
                  <Route path='/complaint-category' element={<ComplaintCategoryListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='complaint_priority' />}>
                  <Route path='/complaint-priority' element={<ComplaintPriorityListPage />} />
                </Route>

                <Route path='/collaborator/me' element={<CollaboratorMePage />} />
                <Route element={<PermissionRoute permissionEntity='collaborator' />}>
                  <Route path='/collaborator' element={<CollaboratorListPage />} />
                  <Route path='/collaborator/import' element={<CollaboratorImportPage />} />
                  <Route path='/collaborator/:id' element={<CollaboratorDetailPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='audit' />}>
                  <Route path='/audit' element={<AuditListPage />} />
                </Route>
                <Route element={<PermissionRoute permissionEntity='data_import' />}>
                  <Route path='/import' element={<ImportPage />} />
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
