<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\System\Models\System;

class SystemTableSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSystems();
        $this->seedModuleSystems();
        $this->seedModulePermissionEntities();
        $this->seedModuleRoutes();
        $this->seedModuleVisibility();
        $this->seedModuleIcons();
    }

    private function seedSystems(): void
    {
        $file = base_path('database/seeders/csv/systems.csv');
        $rows = array_map('str_getcsv', file($file));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) < count($header)) {
                continue;
            }

            $data = array_combine($header, $row);

            System::updateOrCreate(
                ['id' => $data['id']],
                [
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'description' => $data['description'],
                    'icon' => $data['icon'],
                    'sort_order' => (int) $data['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedModuleSystems(): void
    {
        $file = base_path('database/seeders/csv/module_system.csv');
        $rows = array_map('str_getcsv', file($file));
        $header = array_shift($rows);

        DB::table('module_system')->truncate();

        $existingModuleIds = DB::table('module')->pluck('id')->flip()->all();
        $existingSystemIds = DB::table('system')->pluck('id')->flip()->all();
        $skipped = 0;

        foreach ($rows as $row) {
            if (count($row) < count($header)) {
                continue;
            }

            $data = array_combine($header, $row);

            if (! isset($existingModuleIds[$data['module_id']]) || ! isset($existingSystemIds[$data['system_id']])) {
                $skipped++;

                continue;
            }

            DB::table('module_system')->insert([
                'id' => Str::uuid7()->toString(),
                'module_id' => $data['module_id'],
                'system_id' => $data['system_id'],
                'sort_order' => (int) $data['sort_order'],
                'group_label' => $data['group_label'] !== '' ? $data['group_label'] : null,
                'group_icon' => $data['group_icon'] !== '' ? $data['group_icon'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($skipped > 0) {
            $this->command->warn("SystemTableSeeder: {$skipped} module_system rows skipped (modules not yet seeded).");
        }
    }

    /**
     * Actualiza la columna permission_entity de los modulos.
     * Keys must match exactly the `name` values in modules.csv.
     */
    private function seedModulePermissionEntities(): void
    {
        $mapping = [
            'Dashboard' => 'dashboard',
            'Usuarios' => 'user',
            'Roles' => 'role',
            'Empresas' => 'company',
            'Ajustes de Plataforma' => 'setting',
            'Tenants' => 'tenant',
            'Auditoría' => 'audit',
            'Colaboradores' => 'collaborator',
            'Correos' => 'email',
            'Notificaciones' => 'notification',
            // Módulos de GESTIÓN: 'update:entidad' — solo los ve quien puede
            // gestionarlos. Los residentes tienen read.{entidad} para sus vistas
            // propias y el directorio, pero NO deben ver las páginas CRUD de
            // administración (estructura, perfiles de todos, lista de unidades…).
            'Torres' => 'update:tower',
            'Secciones' => 'update:section',
            'Características de Unidad' => 'update:unit_feature',
            'Perfil de Residente' => 'update:user_profile',
            'Unidades' => 'update:unit',
            // Vista PROPIA del residente (su unidad), distinta de la lista de gestión.
            'Mi Unidad' => 'read:unit',
            'Vehículos' => 'update:vehicle',
            // Catálogos autoadministrables: 'update:entidad' = solo aparecen en el
            // menú para quien puede gestionarlos (los residentes tienen read).
            'Tipos de Unidad' => 'update:unit_type',
            'Estados de Unidad' => 'update:unit_status',
            'Relaciones de Unidad' => 'unit_relationship',
            'Asignaciones de Unidad' => 'update:unit_user',
            // Board & Employee (Module 3). Mesa Directiva queda read-visible: los
            // residentes ven el directorio (§7.2/7.3.3 público); la página oculta
            // las acciones de gestión a quien no tiene update.board_period.
            'Mesa Directiva' => 'board_period',
            'Miembros de Mesa' => 'board_member',
            'Cargos de Mesa' => 'board_role',
            'Estados de Periodo' => 'board_period_status',
            'Puestos de Empleado' => 'update:employee_position',
            'Frecuencia de Pago' => 'update:pay_frequency',
            'Turnos de Empleado' => 'update:employee_shift',
            // Treasury (Module 5 foundation)
            'Cuentas Bancarias' => 'bank_account',
            'Categorías de Egreso' => 'update:expense_category',
            'Tipos de Cuenta' => 'bank_account_type',
            'Propósitos de Cuenta' => 'update:bank_account_purpose',
            // Collections (Module 4)
            'Conceptos de Cobro' => 'charge_concept',
            'Programación de Cobros' => 'charge_policy',
            'Cobros programados' => 'charge_batch',
            // Lista de gestión de cargos: el residente ve sus cargos por Estado
            // de Cuenta, no la administración de todos.
            'Cargos' => 'update:charge',
            'Pagos' => 'payment',
            'Mi estado de cuenta' => 'payment_application',
            // El dashboard de morosidad (GET /delinquency) autoriza read.payment,
            // así que el menú debe filtrar por la misma habilidad — no por
            // credit_balance, que los residentes sí pueden leer.
            'Morosidad' => 'payment',
            'Estados de Cargo' => 'charge_status',
            'Estados de Pago' => 'payment_status',
            'Estados de Cobro' => 'charge_batch_status',
            'Categorías de Concepto' => 'update:charge_concept_category',
            'Modos de Monto' => 'amount_mode',
            'Modos de Recargo' => 'surcharge_mode',
            'Periodicidades' => 'periodicity',
            'Métodos de Pago' => 'payment_method',
            // Treasury expenses (Module 5-rest)
            'Egresos' => 'expense',
            'Estados de Egreso' => 'expense_status',
            'Tipos de Beneficiario' => 'beneficiary_type',
            // Providers (Module 6)
            'Proveedores' => 'provider',
            'Tipos de Proveedor' => 'update:provider_type',
            'Tipos de Servicio' => 'update:provider_service_type',
            // Projects (Module 7)
            'Proyectos Comunitarios' => 'community_project',
            'Estados de Proyecto' => 'community_project_status',
            'Categorías de Proyecto' => 'update:community_project_category',
            'Tipos de Participación' => 'participation_type',
            'Estados de Aportación' => 'project_contribution_status',
            'Aportaciones' => 'update:project_contribution',
            'Comentarios de Proyecto' => 'project_comment',
            // Announcements (Module 8)
            'Avisos' => 'announcement',
            'Tipos de Aviso' => 'update:announcement_type',
            'Prioridades de Aviso' => 'announcement_priority',
            'Estados de Aviso' => 'announcement_status',
            // Chat (Module 9)
            'Canales de Chat' => 'chat_channel',
            'Tipos de Canal' => 'chat_channel_type',
            'Mensajes de Chat' => 'chat_message',
            'Miembros de Canal' => 'chat_channel_member',
            // Access (Module 10)
            // Panel de caseta (escanear/registrar entradas): requiere access_log,
            // igual que su ruta /access. Los residentes NO lo ven (generan sus QR
            // por 'Visitantes'); solo seguridad/guardias con read.access_log.
            'Control de Accesos' => 'access_log',
            'Motivos de Visita' => 'update:visitor_motive',
            'Estados de Visitante' => 'visitor_status',
            'Tipos de QR' => 'qr_type',
            'Tipos de Entrada' => 'access_entry_type',
            'Verificaciones de Acceso' => 'access_verification',
            'Tipos de Lista Negra' => 'blacklist_type',
            'Visitantes' => 'visitor',
            'Bitácora de Accesos' => 'access_log',
            'Lista Negra' => 'blacklist_entry',
            // Reports (Module 11)
            'Reportes' => 'report',
            'Reportes Programados' => 'scheduled_report',
            // Configuration (Module 12)
            'Configuración' => 'configuration',
            // DataImport (Module 13)
            'Importación de Pagos' => 'data_import',
            // Tableros (Module 14) — read-only analytics dashboards.
            'Estado de Resultados' => 'report_pnl',
            'Tesorería (Tablero)' => 'report_treasury',
            'Gastos (Tablero)' => 'report_expenses',
            'Cobranza (Tablero)' => 'report_collections',
            'Proyectos (Tablero)' => 'report_projects',
            // Portería — Paquetería (Module 15)
            'Paquetería' => 'package',
            'Paquetería (Reportes)' => 'report_package',
            // Catálogos administrables: 'update:' exige esa habilidad exacta en el
            // menú, así solo los ve quien puede gestionarlos (patrón provider_type).
            // El gating por add-on de los ENDPOINTS no cambia: las rutas usan
            // moduleEnabled:package (fila 'Paquetería').
            'Transportistas' => 'update:package_carrier',
            'Tipos de Paquete' => 'update:package_type',
            'Estados de Paquete' => 'package_status',
            // Billing v2 plan admin (Core / super admin)
            'Planes y Precios' => 'billing_package',
            // Amenity Reservations (Module 16, S17 menu wiring). Plain entity,
            // NO verb prefix: admin (full CRUD) and residents (read.amenity +
            // read/create.amenity_reservation from S2) share the SAME menu
            // entry — the frontend page itself redirects a viewer without
            // update.{entity} to the resident-facing route (AmenityListPage →
            // /amenities, AmenityReservationListPage → /my-reservations),
            // mirroring the established PackageListPage → /my-packages pattern
            // instead of splitting into a second "Mis…" module row.
            'Amenidades' => 'amenity',
            'Reservaciones' => 'amenity_reservation',
            // Incidencias (Complaint module)
            'Incidencias' => 'complaint',
            // Catálogos autoadministrables: 'update:' exige esa habilidad exacta en el
            // menú (patrón provider_type / package_carrier).
            'Categorías de Incidencia' => 'update:complaint_category',
            'Prioridades de Incidencia' => 'update:complaint_priority',
            'Estados de Incidencia' => 'complaint_status',
            // ChildRecord / Clinic (EDI clinic-and-child-registry). Cuidadores has
            // permissions but no sidebar page (D11/D17): managed from the Child
            // detail. Sex/PopulationType are central FSM-adjacent catalogs
            // (read-only for tenant roles); the three tenant-scoped catalogs use
            // the 'update:' gate like every other autoadministrable catalog.
            'Centros de Salud' => 'health_center',
            'Profesionales de Salud' => 'practitioner_profile',
            'Niños' => 'child',
            'Cuidadores' => 'caregiver',
            'Visitas' => 'visit',
            // Agenda (odd/tasks/edi-agenda.md)
            'Agenda' => 'appointment',
            'Sexos' => 'sex',
            'Tipos de Población' => 'population_type',
            'Parentescos' => 'update:caregiver_relationship',
            'Escolaridades' => 'update:education_level',
            'Ocupaciones' => 'update:occupation',
            // Instrument (EDI instrument catalog): read-only, super-admin-only
            // (Core system). See RolePermissionTableSeeder $permissionsOnlySuperAdmin.
            'Instrumento EDI' => 'instrument',
            // Screening application (edi-screening-application D21). Tamizajes
            // is visible since edi-screening-ui; Pruebas Confirmatorias stays
            // hidden (see seedModuleVisibility below).
            'Prueba EDI' => 'screening',
            'Pruebas Confirmatorias' => 'confirmatory_test',
            // Stimulation (edi-stimulation-sessions). The 6 tenant catalogs use
            // 'update:' like every other autoadministrable catalog; the 3
            // central catalogs (session_section/stimulation_area/
            // attendance_payment_status) are plain entities, read-only for
            // tenant roles (RolePermissionTableSeeder $centralCatalogs) and
            // hidden module rows (seedModuleVisibility below).
            'Sesiones de Estimulación' => 'stimulation_session',
            'Grupos de Estimulación' => 'stimulation_group',
            'Dinámicas de Estimulación' => 'update:stimulation_dynamic',
            'Zonas Corporales' => 'update:body_zone',
            'Movimientos de Masaje' => 'update:massage_movement',
            'Materiales Didácticos' => 'update:didactic_material',
            'Canciones' => 'update:song',
            'Tipos de Grupo' => 'update:stimulation_group_type',
            'Secciones de Sesión' => 'session_section',
            'Áreas de Estimulación' => 'stimulation_area',
            'Estatus de Pago de Asistencia' => 'attendance_payment_status',
            // FollowUp (edi-followup-and-referral). Referencias has no
            // standalone page (managed from the follow-up detail) and stays
            // hidden (see seedModuleVisibility below); Especialidades de
            // Referencia is a tenant catalog, 'update:' gate like every
            // other autoadministrable catalog.
            'Seguimientos' => 'follow_up',
            'Referencias' => 'referral',
            'Especialidades de Referencia' => 'update:referral_specialty',
            // Reports (edi-reports-and-indicators D7/D12). Aggregate vs
            // nominal are DIFFERENT permission entities so the menu split
            // mirrors the PII boundary: clinical staff can see the
            // dashboard without ever seeing the nominal/PII page.
            'Indicadores' => 'screening_indicator',
            'Listado nominal' => 'nominal_report',
            // EDI legacy parity (odd/tasks/edi-legacy-parity.md)
            'Concentrado EDI' => 'edi_concentrate',
            'Reporte general' => 'general_report',
            'Edad corregida' => 'corrected_age',
            'Estado nutricional' => 'nutritional_assessment',
        ];

        foreach ($mapping as $moduleName => $entity) {
            DB::table('module')->where('name', $moduleName)->update([
                'permission_entity' => $entity,
            ]);
        }
    }

    private function seedModuleRoutes(): void
    {
        $routes = [
            'Usuarios' => '/user',
            'Roles' => '/role',
            'Tenants' => '/tenant',
            'Auditoría' => '/audit',
            'Colaboradores' => '/collaborator',
            'Empresas' => '/company',
            'Dashboard' => '/dashboard',
            'Correos' => '/email',
            'Notificaciones' => '/notification',
            'Torres' => '/tower',
            'Secciones' => '/section',
            'Características de Unidad' => '/unit-feature',
            'Perfil de Residente' => '/user-profile',
            'Unidades' => '/unit',
            'Mi Unidad' => '/my-unit',
            'Vehículos' => '/vehicle',
            'Tipos de Unidad' => '/unit-type',
            'Estados de Unidad' => '/unit-status',
            'Relaciones de Unidad' => '/unit-relationship',
            'Asignaciones de Unidad' => '/unit-user',
            // Board & Employee (Module 3)
            'Mesa Directiva' => '/board-period',
            'Miembros de Mesa' => '/board-member',
            'Puestos de Empleado' => '/employee-position',
            'Frecuencia de Pago' => '/pay-frequency',
            'Turnos de Empleado' => '/employee-shift',
            // Treasury (Module 5 foundation)
            'Cuentas Bancarias' => '/bank-account',
            'Categorías de Egreso' => '/expense-category',
            'Propósitos de Cuenta' => '/bank-account-purpose',
            // Collections (Module 4)
            'Conceptos de Cobro' => '/charge-concept',
            'Programación de Cobros' => '/charge-policy',
            'Cobros programados' => '/charge-batch',
            'Cargos' => '/charge',
            'Pagos' => '/payment',
            'Mi estado de cuenta' => '/statement',
            'Morosidad' => '/delinquency',
            // Treasury expenses (Module 5-rest)
            'Egresos' => '/expense',
            // Providers (Module 6)
            'Proveedores' => '/provider',
            'Tipos de Proveedor' => '/provider-type',
            'Tipos de Servicio' => '/provider-service-type',
            // Projects (Module 7)
            'Proyectos Comunitarios' => '/community-project',
            'Aportaciones' => '/project-contribution',
            'Categorías de Proyecto' => '/community-project-category',
            // Collections catalogs (autoadministrables)
            'Categorías de Concepto' => '/charge-concept-category',
            // Announcements (Module 8)
            'Avisos' => '/announcement',
            'Tipos de Aviso' => '/announcement-type',
            // Access catalogs (autoadministrables)
            'Motivos de Visita' => '/visitor-motive',
            // Chat (Module 9)
            'Canales de Chat' => '/chat-channel',
            // Access (Module 10)
            'Control de Accesos' => '/access',
            'Visitantes' => '/visitor',
            'Bitácora de Accesos' => '/access-log',
            'Lista Negra' => '/blacklist',
            // Reports (Module 11)
            'Reportes' => '/reports',
            'Reportes Programados' => '/scheduled-reports',
            // Configuration (Module 12)
            'Configuración' => '/configuration',
            // DataImport (Module 13)
            'Importación de Pagos' => '/import',
            // Tableros (Module 14)
            'Estado de Resultados' => '/tableros/resultados',
            'Tesorería (Tablero)' => '/tableros/tesoreria',
            'Gastos (Tablero)' => '/tableros/gastos',
            'Cobranza (Tablero)' => '/tableros/cobranza',
            'Proyectos (Tablero)' => '/tableros/proyectos',
            // Portería — Paquetería (Module 15)
            'Paquetería' => '/package',
            'Paquetería (Reportes)' => '/package/reports',
            'Transportistas' => '/package-carrier',
            'Tipos de Paquete' => '/package-type',
            // Billing v2 plan admin (Core / super admin)
            'Planes y Precios' => '/billing-package',
            // Amenity Reservations (Module 16) — canonical admin destinations.
            // The resident-facing routes (/amenities, /my-reservations) are
            // reached via the in-page redirect described in
            // seedModulePermissionEntities(), not a second module row.
            'Amenidades' => '/amenity',
            'Reservaciones' => '/amenity-reservation',
            // Incidencias (Complaint module)
            'Incidencias' => '/complaint',
            'Categorías de Incidencia' => '/complaint-category',
            'Prioridades de Incidencia' => '/complaint-priority',
            // ChildRecord / Clinic. Hidden modules (Cuidadores, Sexos, Tipos de
            // Población) need no route.
            'Centros de Salud' => '/health-center',
            'Profesionales de Salud' => '/practitioner',
            'Niños' => '/child',
            'Visitas' => '/visit',
            'Agenda' => '/agenda',
            'Parentescos' => '/caregiver-relationship',
            'Escolaridades' => '/education-level',
            'Ocupaciones' => '/occupation',
            // Instrument (EDI instrument catalog)
            'Instrumento EDI' => '/instrument',
            // Screening application (D21) — Pruebas Confirmatorias has no route:
            // hidden module, managed from the screening detail (edi-screening-ui).
            'Prueba EDI' => '/screening',
            // Stimulation (edi-stimulation-sessions). Hidden modules (Secciones
            // de Sesión, Áreas de Estimulación, Estatus de Pago de Asistencia)
            // need no route.
            'Sesiones de Estimulación' => '/stimulation-session',
            'Grupos de Estimulación' => '/stimulation-group',
            'Dinámicas de Estimulación' => '/stimulation-dynamic',
            'Zonas Corporales' => '/body-zone',
            'Movimientos de Masaje' => '/massage-movement',
            'Materiales Didácticos' => '/didactic-material',
            'Canciones' => '/song',
            'Tipos de Grupo' => '/stimulation-group-type',
            // FollowUp (edi-followup-and-referral). Referencias has no route
            // (hidden module, managed from the follow-up detail).
            'Seguimientos' => '/follow-up',
            'Especialidades de Referencia' => '/referral-specialty',
            // Reports (edi-reports-and-indicators)
            'Indicadores' => '/reports/indicators',
            'Listado nominal' => '/reports/nominal',
            // EDI legacy parity — Estado nutricional lives in the child record (hidden row).
            'Concentrado EDI' => '/reports/edi-concentrate',
            'Reporte general' => '/reports/general',
            'Edad corregida' => '/corrected-age',
        ];

        foreach ($routes as $moduleName => $route) {
            DB::table('module')->where('name', $moduleName)->update([
                'route' => $route,
            ]);
        }
    }

    private function seedModuleVisibility(): void
    {
        // All modules visible by default.
        DB::table('module')->update(['visible' => true]);

        // Módulos internos ocultos del sidebar. Los catálogos CENTRALES (FSM /
        // selectores de comportamiento, solo lectura para el tenant) se quedan
        // aquí; los 12 catálogos AUTOADMINISTRABLES son visibles y se filtran
        // por 'update:entidad' en MenuController (solo los ve quien los gestiona).
        $hidden = [
            // Legacy CRM: módulo Setting (Core) sin página en la app React.
            'Ajustes de Plataforma',
            // Projects catalogs — centrales
            'Estados de Proyecto',
            'Tipos de Participación',
            'Estados de Aportación',
            'Comentarios de Proyecto',
            // Announcements catalogs — centrales
            'Prioridades de Aviso',
            'Estados de Aviso',
            // Chat catalogs — centrales/internos
            'Tipos de Canal',
            'Mensajes de Chat',
            'Miembros de Canal',
            'Relaciones de Unidad',
            // Board catalogs — centrales
            'Cargos de Mesa',
            'Estados de Periodo',
            'Miembros de Mesa',
            // Treasury catalogs — centrales
            'Tipos de Cuenta',
            // Collections catalogs — centrales
            'Estados de Cargo',
            'Estados de Pago',
            'Estados de Cobro',
            'Modos de Monto',
            'Modos de Recargo',
            'Periodicidades',
            'Métodos de Pago',
            // Expense catalogs — centrales
            'Estados de Egreso',
            'Tipos de Beneficiario',
            // Access catalogs — centrales
            'Estados de Visitante',
            'Tipos de QR',
            'Tipos de Entrada',
            'Verificaciones de Acceso',
            'Tipos de Lista Negra',
            // Reports catalogs — internal
            'Reportes Programados',
            // Portería — catálogos centrales ocultos; Transportistas y Tipos de Paquete
            // son autoadministrables y tienen páginas CRUD en la app — visibles en el sidebar.
            'Estados de Paquete',
            'Paquetería (Reportes)',
            // Incidencias — catálogo central FSM oculto; Categorías y Prioridades son
            // autoadministrables y tienen páginas CRUD — visibles en el sidebar.
            'Estados de Incidencia',
            // ChildRecord / Clinic (D11/D17): Cuidadores is managed from the
            // Child detail, not a standalone page. Sex/PopulationType are
            // central catalogs, read-only for tenant roles.
            'Cuidadores',
            'Sexos',
            'Tipos de Población',
            // Screening application (D21). Tamizajes shipped its frontend in
            // edi-screening-ui (REQ-SCR-013 delta) — no longer hidden.
            // Pruebas Confirmatorias has no standalone page (managed from the
            // screening detail, REQ-SUI-010) and stays hidden.
            'Pruebas Confirmatorias',
            // Stimulation (edi-stimulation-sessions). The 3 central catalogs
            // are read-only for tenant roles — hidden module rows, same as
            // Sex/PopulationType above.
            'Secciones de Sesión',
            'Áreas de Estimulación',
            'Estatus de Pago de Asistencia',
            // FollowUp (edi-followup-and-referral). Referrals live inside
            // the follow-up detail page — a menu row would 404.
            'Referencias',
            // EDI legacy parity: Estado nutricional is a tab of the child record.
            'Estado nutricional',
            // Clinical record (odd/tasks/edi-clinical-record.md S5): visits are
            // worked from the child record; the /visit route stays reachable.
            'Visitas',
        ];

        DB::table('module')->whereIn('name', $hidden)->update(['visible' => false]);
    }

    private function seedModuleIcons(): void
    {
        $icons = [
            'Dashboard' => 'IconDashboard',
            'Usuarios' => 'IconUser',
            'Roles' => 'IconShield',
            'Empresas' => 'IconBuilding',
            'Ajustes de Plataforma' => 'IconSettings',
            'Tenants' => 'IconBuilding',
            'Auditoría' => 'IconClipboardCheck',
            'Colaboradores' => 'IconUsers',
            'Correos' => 'IconMail',
            'Notificaciones' => 'IconBell',
            'Torres' => 'IconBuilding',
            'Secciones' => 'IconLayoutGrid',
            'Características de Unidad' => 'IconList',
            'Perfil de Residente' => 'IconUserCircle',
            'Unidades' => 'IconHome',
            'Mi Unidad' => 'IconBuilding',
            'Vehículos' => 'IconCar',
            'Tipos de Unidad' => 'IconCategory',
            'Estados de Unidad' => 'IconCircleCheck',
            'Relaciones de Unidad' => 'IconUsers',
            'Asignaciones de Unidad' => 'IconUserPlus',
            // Board & Employee (Module 3)
            'Mesa Directiva' => 'IconUsers',
            'Miembros de Mesa' => 'IconUserCheck',
            'Cargos de Mesa' => 'IconBriefcase',
            'Estados de Periodo' => 'IconCircleCheck',
            'Puestos de Empleado' => 'IconBriefcase',
            'Frecuencia de Pago' => 'IconCash',
            'Turnos de Empleado' => 'IconClock',
            // Treasury (Module 5 foundation)
            'Cuentas Bancarias' => 'IconBuildingBank',
            'Categorías de Egreso' => 'IconTags',
            'Tipos de Cuenta' => 'IconCategory',
            'Propósitos de Cuenta' => 'IconTarget',
            // Collections (Module 4)
            'Conceptos de Cobro' => 'IconFileInvoice',
            'Programación de Cobros' => 'IconListDetails',
            'Cobros programados' => 'IconReceipt',
            'Cargos' => 'IconCreditCard',
            'Pagos' => 'IconCash',
            'Mi estado de cuenta' => 'IconClipboardList',
            'Morosidad' => 'IconAlertTriangle',
            'Estados de Cargo' => 'IconCircleCheck',
            'Estados de Pago' => 'IconCircleCheck',
            'Estados de Cobro' => 'IconCircleCheck',
            'Categorías de Concepto' => 'IconCategory',
            'Modos de Monto' => 'IconAdjustments',
            'Modos de Recargo' => 'IconAdjustments',
            'Periodicidades' => 'IconCalendar',
            'Métodos de Pago' => 'IconWallet',
            // Treasury expenses (Module 5-rest)
            'Egresos' => 'IconArrowDown',
            'Estados de Egreso' => 'IconCircleCheck',
            'Tipos de Beneficiario' => 'IconUsers',
            // Providers (Module 6)
            'Proveedores' => 'IconTruck',
            'Tipos de Proveedor' => 'IconCategory',
            'Tipos de Servicio' => 'IconListCheck',
            // Projects (Module 7)
            'Proyectos Comunitarios' => 'IconBuildingCommunity',
            'Estados de Proyecto' => 'IconCircleCheck',
            'Categorías de Proyecto' => 'IconCategory',
            'Tipos de Participación' => 'IconUsers',
            'Estados de Aportación' => 'IconCircleCheck',
            'Aportaciones' => 'IconCash',
            'Comentarios de Proyecto' => 'IconMessageCircle',
            // Announcements (Module 8)
            'Avisos' => 'IconSpeakerphone',
            'Tipos de Aviso' => 'IconCategory',
            'Prioridades de Aviso' => 'IconFlag',
            'Estados de Aviso' => 'IconCircleCheck',
            // Chat (Module 9)
            'Canales de Chat' => 'IconMessages',
            'Tipos de Canal' => 'IconCategory',
            'Mensajes de Chat' => 'IconMessage',
            'Miembros de Canal' => 'IconUsers',
            // Access (Module 10)
            'Control de Accesos' => 'IconShieldLock',
            'Motivos de Visita' => 'IconCategory',
            'Estados de Visitante' => 'IconCircleCheck',
            'Tipos de QR' => 'IconQrcode',
            'Tipos de Entrada' => 'IconDoor',
            'Verificaciones de Acceso' => 'IconShield',
            'Tipos de Lista Negra' => 'IconCategory',
            'Visitantes' => 'IconUserPlus',
            'Bitácora de Accesos' => 'IconClipboardList',
            'Lista Negra' => 'IconBan',
            // Reports (Module 11)
            'Reportes' => 'IconChartBar',
            'Reportes Programados' => 'IconCalendarEvent',
            // Configuration (Module 12)
            'Configuración' => 'IconSettings',
            // DataImport (Module 13)
            'Importación de Pagos' => 'IconFileImport',
            // Tableros (Module 14) — individual dashboard icons.
            'Estado de Resultados' => 'IconTrendingUp',
            'Tesorería (Tablero)' => 'IconWallet',
            'Gastos (Tablero)' => 'IconReceipt',
            'Cobranza (Tablero)' => 'IconAlertTriangle',
            'Proyectos (Tablero)' => 'IconTargetArrow',
            // Portería — Paquetería (Module 15)
            'Paquetería' => 'IconBox',
            'Paquetería (Reportes)' => 'IconBox',
            'Transportistas' => 'IconTruck',
            'Tipos de Paquete' => 'IconCategory',
            'Estados de Paquete' => 'IconCircleCheck',
            // Billing v2 plan admin (Core / super admin)
            'Planes y Precios' => 'IconTags',
            // Amenity Reservations (Module 16)
            'Amenidades' => 'IconBuildingCommunity',
            'Reservaciones' => 'IconCalendarEvent',
            // Incidencias (Complaint module)
            'Incidencias' => 'IconAlertTriangle',
            'Categorías de Incidencia' => 'IconCategory',
            'Prioridades de Incidencia' => 'IconFlag',
            'Estados de Incidencia' => 'IconCircleCheck',
            // ChildRecord / Clinic
            'Centros de Salud' => 'IconBuilding',
            'Profesionales de Salud' => 'IconUserCheck',
            'Niños' => 'IconUsers',
            'Cuidadores' => 'IconUsers',
            'Visitas' => 'IconCalendarEvent',
            'Agenda' => 'IconCalendar',
            'Sexos' => 'IconCategory',
            'Tipos de Población' => 'IconCategory',
            'Parentescos' => 'IconUsers',
            'Escolaridades' => 'IconCategory',
            'Ocupaciones' => 'IconBriefcase',
            // Instrument (EDI instrument catalog)
            'Instrumento EDI' => 'IconClipboardHeart',
            // Screening application (D21)
            'Prueba EDI' => 'IconReportMedical',
            'Pruebas Confirmatorias' => 'IconChecklist',
            // Stimulation (edi-stimulation-sessions)
            'Sesiones de Estimulación' => 'IconHeartHandshake',
            'Grupos de Estimulación' => 'IconUsersGroup',
            'Dinámicas de Estimulación' => 'IconCategory',
            'Zonas Corporales' => 'IconBodyScan',
            'Movimientos de Masaje' => 'IconMassage',
            'Materiales Didácticos' => 'IconPackage',
            'Canciones' => 'IconMusic',
            'Tipos de Grupo' => 'IconCategory',
            'Secciones de Sesión' => 'IconCategory',
            'Áreas de Estimulación' => 'IconCategory',
            'Estatus de Pago de Asistencia' => 'IconCategory',
            // FollowUp (edi-followup-and-referral)
            'Seguimientos' => 'IconClipboardList',
            'Referencias' => 'IconUserPlus',
            'Especialidades de Referencia' => 'IconCategory',
            // Reports (edi-reports-and-indicators)
            'Indicadores' => 'IconChartBar',
            'Listado nominal' => 'IconReportMedical',
            'Concentrado EDI' => 'IconTable',
            'Reporte general' => 'IconFileSpreadsheet',
            'Edad corregida' => 'IconCalendarStats',
            'Estado nutricional' => 'IconScale',
        ];

        foreach ($icons as $moduleName => $icon) {
            DB::table('module')->where('name', $moduleName)->update(['icon' => $icon]);
        }
    }
}
