<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use QCH\Core\Models\Role;

class RolePermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (env('DB_CONNECTION') === 'sqlite') {
            \DB::statement('DELETE FROM role_has_permissions');
        } else {
            \DB::statement('TRUNCATE TABLE role_has_permissions');
        }

        // Central fixed-enum catalogs (UUID-keyed, BaseModel::columns($table, false),
        // shared across ALL tenants). Mutating one row would hit every cluster at
        // once (e.g. soft-deleting ChargeStatus::OVERDUE breaks ChargeStatusService
        // platform-wide), so write verbs are platform-exclusive and tenant roles
        // keep read access only (findings global-02, housing-05, collections-06,
        // providers-05, treasury-11, announcements-03, access-05).
        //
        // Los 12 catálogos AUTOADMINISTRABLES (unit_type, unit_status,
        // employee_position, pay_frequency, employee_shift, bank_account_purpose,
        // charge_concept_category, community_project_category, provider_type,
        // provider_service_type, visitor_motive, announcement_type) son
        // tenant-scoped (BelongsToTenant + TenantCatalogSeeder) y NO van aquí:
        // sus writes fluyen a owner/company_admin/administrator vía whereNotIn,
        // acotados a las filas del propio tenant por TenantScope.
        $centralCatalogs = [
            'unit_relationship',
            'board_role', 'board_period_status',
            'charge_status', 'payment_status', 'charge_batch_status',
            'amount_mode', 'surcharge_mode', 'periodicity', 'payment_method',
            'expense_status', 'beneficiary_type', 'bank_account_type',
            'community_project_status', 'participation_type', 'project_contribution_status',
            'announcement_priority', 'announcement_status',
            'chat_channel_type',
            'visitor_status', 'qr_type', 'access_entry_type', 'access_verification', 'blacklist_type',
            'complaint_status',
        ];

        $centralCatalogWrites = [];
        foreach ($centralCatalogs as $catalog) {
            foreach (['create', 'update', 'delete', 'restore'] as $verb) {
                $centralCatalogWrites[] = "{$verb}.{$catalog}";
            }
        }

        // Permissions that are exclusively for super-admin (platform administration).
        $permissionsOnlySuperAdmin = array_merge([
            'read.email', 'update.email', 'delete.email', 'create.email', 'restore.email', 'report.email',
            'read.tenant', 'update.tenant', 'delete.tenant', 'create.tenant', 'restore.tenant', 'report.tenant',
            'read.role', 'update.role', 'delete.role', 'create.role', 'restore.role', 'report.role',
            'read.permission', 'update.permission', 'delete.permission', 'create.permission', 'restore.permission', 'report.permission',
            'read.module', 'update.module', 'delete.module', 'create.module', 'restore.module', 'report.module',
            // Billing v2 plan admin (plan catalog + price matrix): platform-exclusive.
            'read.billing_package', 'update.billing_package', 'delete.billing_package', 'create.billing_package', 'restore.billing_package', 'report.billing_package',
            // The audit log is append-only for tenant roles (spec §18.2.1): they may
            // read/report it, never write it.
            'create.audit', 'update.audit', 'delete.audit', 'restore.audit',
        ], $centralCatalogWrites);

        // Base permission catalog consumed ONLY by the super-admin grant below
        // (exclusives + this list). Tenant roles receive their permissions via the
        // whereNotIn queries / the Kivo7 role sets, so entries here that also
        // appear in $permissionsOnlySuperAdmin stay platform-exclusive.
        $commonPermissions = [
            'read.user', 'update.user', 'delete.user', 'create.user', 'restore.user', 'report.user',
            'read.audit', 'update.audit', 'delete.audit', 'create.audit', 'restore.audit', 'report.audit',
            'read.dashboard', 'update.dashboard', 'delete.dashboard', 'create.dashboard', 'restore.dashboard', 'report.dashboard',
            'read.company', 'update.company', 'delete.company', 'create.company', 'restore.company', 'report.company',
            'read.tower', 'update.tower', 'delete.tower', 'create.tower', 'restore.tower', 'report.tower',
            'read.section', 'update.section', 'delete.section', 'create.section', 'restore.section', 'report.section',
            'read.unit_feature', 'update.unit_feature', 'delete.unit_feature', 'create.unit_feature', 'restore.unit_feature', 'report.unit_feature',
            'read.user_profile', 'update.user_profile', 'delete.user_profile', 'create.user_profile', 'restore.user_profile', 'report.user_profile',
            'read.unit', 'update.unit', 'delete.unit', 'create.unit', 'restore.unit', 'report.unit',
            'read.vehicle', 'update.vehicle', 'delete.vehicle', 'create.vehicle', 'restore.vehicle', 'report.vehicle',
            'read.unit_type', 'update.unit_type', 'delete.unit_type', 'create.unit_type', 'restore.unit_type', 'report.unit_type',
            'read.unit_status', 'update.unit_status', 'delete.unit_status', 'create.unit_status', 'restore.unit_status', 'report.unit_status',
            'read.unit_relationship', 'update.unit_relationship', 'delete.unit_relationship', 'create.unit_relationship', 'restore.unit_relationship', 'report.unit_relationship',
            'read.unit_user', 'update.unit_user', 'delete.unit_user', 'create.unit_user', 'restore.unit_user', 'report.unit_user',
            // Board & Employee (Module 3)
            'read.board_period', 'update.board_period', 'delete.board_period', 'create.board_period', 'restore.board_period', 'report.board_period',
            'read.board_member', 'update.board_member', 'delete.board_member', 'create.board_member', 'restore.board_member', 'report.board_member',
            'read.board_role', 'update.board_role', 'delete.board_role', 'create.board_role', 'restore.board_role', 'report.board_role',
            'read.board_period_status', 'update.board_period_status', 'delete.board_period_status', 'create.board_period_status', 'restore.board_period_status', 'report.board_period_status',
            'read.employee_position', 'update.employee_position', 'delete.employee_position', 'create.employee_position', 'restore.employee_position', 'report.employee_position',
            'read.pay_frequency', 'update.pay_frequency', 'delete.pay_frequency', 'create.pay_frequency', 'restore.pay_frequency', 'report.pay_frequency',
            'read.employee_shift', 'update.employee_shift', 'delete.employee_shift', 'create.employee_shift', 'restore.employee_shift', 'report.employee_shift',
            // Treasury (Module 5 foundation)
            'read.bank_account', 'update.bank_account', 'delete.bank_account', 'create.bank_account', 'restore.bank_account', 'report.bank_account',
            'read.expense_category', 'update.expense_category', 'delete.expense_category', 'create.expense_category', 'restore.expense_category', 'report.expense_category',
            'read.bank_account_type', 'update.bank_account_type', 'delete.bank_account_type', 'create.bank_account_type', 'restore.bank_account_type', 'report.bank_account_type',
            'read.bank_account_purpose', 'update.bank_account_purpose', 'delete.bank_account_purpose', 'create.bank_account_purpose', 'restore.bank_account_purpose', 'report.bank_account_purpose',
            // Collections (Module 4)
            'read.charge_concept', 'update.charge_concept', 'delete.charge_concept', 'create.charge_concept', 'restore.charge_concept', 'report.charge_concept',
            'read.charge_policy', 'update.charge_policy', 'delete.charge_policy', 'create.charge_policy', 'restore.charge_policy', 'report.charge_policy',
            'read.charge_batch', 'update.charge_batch', 'delete.charge_batch', 'create.charge_batch', 'restore.charge_batch', 'report.charge_batch',
            'read.charge', 'update.charge', 'delete.charge', 'create.charge', 'restore.charge', 'report.charge',
            'read.payment', 'update.payment', 'delete.payment', 'create.payment', 'restore.payment', 'report.payment',
            'read.payment_application', 'update.payment_application', 'delete.payment_application', 'create.payment_application', 'restore.payment_application', 'report.payment_application',
            'read.credit_balance', 'update.credit_balance', 'delete.credit_balance', 'create.credit_balance', 'restore.credit_balance', 'report.credit_balance',
            'read.charge_status', 'update.charge_status', 'delete.charge_status', 'create.charge_status', 'restore.charge_status', 'report.charge_status',
            'read.payment_status', 'update.payment_status', 'delete.payment_status', 'create.payment_status', 'restore.payment_status', 'report.payment_status',
            'read.charge_batch_status', 'update.charge_batch_status', 'delete.charge_batch_status', 'create.charge_batch_status', 'restore.charge_batch_status', 'report.charge_batch_status',
            'read.charge_concept_category', 'update.charge_concept_category', 'delete.charge_concept_category', 'create.charge_concept_category', 'restore.charge_concept_category', 'report.charge_concept_category',
            'read.amount_mode', 'update.amount_mode', 'delete.amount_mode', 'create.amount_mode', 'restore.amount_mode', 'report.amount_mode',
            'read.surcharge_mode', 'update.surcharge_mode', 'delete.surcharge_mode', 'create.surcharge_mode', 'restore.surcharge_mode', 'report.surcharge_mode',
            'read.periodicity', 'update.periodicity', 'delete.periodicity', 'create.periodicity', 'restore.periodicity', 'report.periodicity',
            'read.payment_method', 'update.payment_method', 'delete.payment_method', 'create.payment_method', 'restore.payment_method', 'report.payment_method',
            // Treasury expenses (Module 5-rest)
            'read.expense', 'update.expense', 'delete.expense', 'create.expense', 'restore.expense', 'report.expense',
            'read.expense_status', 'update.expense_status', 'delete.expense_status', 'create.expense_status', 'restore.expense_status', 'report.expense_status',
            'read.beneficiary_type', 'update.beneficiary_type', 'delete.beneficiary_type', 'create.beneficiary_type', 'restore.beneficiary_type', 'report.beneficiary_type',
            // Providers (Module 6)
            'read.provider', 'update.provider', 'delete.provider', 'create.provider', 'restore.provider', 'report.provider',
            'read.provider_type', 'update.provider_type', 'delete.provider_type', 'create.provider_type', 'restore.provider_type', 'report.provider_type',
            'read.provider_service_type', 'update.provider_service_type', 'delete.provider_service_type', 'create.provider_service_type', 'restore.provider_service_type', 'report.provider_service_type',
            // Projects (Module 7)
            'read.community_project', 'update.community_project', 'delete.community_project', 'create.community_project', 'restore.community_project', 'report.community_project',
            'read.community_project_status', 'update.community_project_status', 'delete.community_project_status', 'create.community_project_status', 'restore.community_project_status', 'report.community_project_status',
            'read.community_project_category', 'update.community_project_category', 'delete.community_project_category', 'create.community_project_category', 'restore.community_project_category', 'report.community_project_category',
            'read.participation_type', 'update.participation_type', 'delete.participation_type', 'create.participation_type', 'restore.participation_type', 'report.participation_type',
            'read.project_contribution_status', 'update.project_contribution_status', 'delete.project_contribution_status', 'create.project_contribution_status', 'restore.project_contribution_status', 'report.project_contribution_status',
            'read.project_contribution', 'update.project_contribution', 'delete.project_contribution', 'create.project_contribution', 'restore.project_contribution', 'report.project_contribution',
            'read.project_comment', 'update.project_comment', 'delete.project_comment', 'create.project_comment', 'restore.project_comment', 'report.project_comment',
            // Announcements (Module 8)
            'read.announcement', 'update.announcement', 'delete.announcement', 'create.announcement', 'restore.announcement', 'report.announcement',
            'read.announcement_type', 'update.announcement_type', 'delete.announcement_type', 'create.announcement_type', 'restore.announcement_type', 'report.announcement_type',
            'read.announcement_priority', 'update.announcement_priority', 'delete.announcement_priority', 'create.announcement_priority', 'restore.announcement_priority', 'report.announcement_priority',
            'read.announcement_status', 'update.announcement_status', 'delete.announcement_status', 'create.announcement_status', 'restore.announcement_status', 'report.announcement_status',
            // Chat (Module 9)
            'read.chat_channel', 'update.chat_channel', 'delete.chat_channel', 'create.chat_channel', 'restore.chat_channel', 'report.chat_channel',
            'read.chat_channel_type', 'update.chat_channel_type', 'delete.chat_channel_type', 'create.chat_channel_type', 'restore.chat_channel_type', 'report.chat_channel_type',
            'read.chat_message', 'update.chat_message', 'delete.chat_message', 'create.chat_message', 'restore.chat_message', 'report.chat_message',
            'read.chat_channel_member', 'update.chat_channel_member', 'delete.chat_channel_member', 'create.chat_channel_member', 'restore.chat_channel_member', 'report.chat_channel_member',
            // Access (Module 10)
            'read.visitor', 'update.visitor', 'delete.visitor', 'create.visitor', 'restore.visitor', 'report.visitor',
            'read.visitor_motive', 'update.visitor_motive', 'delete.visitor_motive', 'create.visitor_motive', 'restore.visitor_motive', 'report.visitor_motive',
            'read.visitor_status', 'update.visitor_status', 'delete.visitor_status', 'create.visitor_status', 'restore.visitor_status', 'report.visitor_status',
            'read.qr_type', 'update.qr_type', 'delete.qr_type', 'create.qr_type', 'restore.qr_type', 'report.qr_type',
            'read.access_entry_type', 'update.access_entry_type', 'delete.access_entry_type', 'create.access_entry_type', 'restore.access_entry_type', 'report.access_entry_type',
            'read.access_verification', 'update.access_verification', 'delete.access_verification', 'create.access_verification', 'restore.access_verification', 'report.access_verification',
            'read.blacklist_type', 'update.blacklist_type', 'delete.blacklist_type', 'create.blacklist_type', 'restore.blacklist_type', 'report.blacklist_type',
            'read.access_log', 'update.access_log', 'delete.access_log', 'create.access_log', 'restore.access_log', 'report.access_log',
            'read.blacklist_entry', 'update.blacklist_entry', 'delete.blacklist_entry', 'create.blacklist_entry', 'restore.blacklist_entry', 'report.blacklist_entry',
            // Reports (Module 11)
            'read.report', 'update.report', 'delete.report', 'create.report', 'restore.report', 'report.report',
            'read.scheduled_report', 'update.scheduled_report', 'delete.scheduled_report', 'create.scheduled_report', 'restore.scheduled_report', 'report.scheduled_report',
            // Configuration (Module 12)
            'read.configuration', 'update.configuration', 'delete.configuration', 'create.configuration', 'restore.configuration', 'report.configuration',
            // DataImport (Module 13)
            'read.data_import', 'create.data_import', 'update.data_import', 'delete.data_import', 'restore.data_import', 'report.data_import',
            // Subscription self-management (spec §16.2 'Gestionar suscripción: Adm ✅').
            'manage.subscription',
            // Dedicated financial-control abilities (spec §8.2 / §9.2): denied to the
            // treasurer, granted to president/administrator (same pattern as
            // discount.charge).
            'cancel.charge', 'void.expense',
        ];

        // Super admin: only their exclusive permissions + common ones.
        $superAdminPermissions = DB::table('permissions')->select(
            'uuid AS permission_id',
            DB::raw("'".Role::SUPER_ADMIN."' as role_id")
        )
            ->whereIn('name', array_merge($permissionsOnlySuperAdmin, $commonPermissions))
            ->get()
            ->map(fn ($x) => (array) $x)
            ->toArray();

        if (! empty($superAdminPermissions)) {
            \DB::table('role_has_permissions')->insert($superAdminPermissions);
        }

        // Account owner: all permissions EXCEPT super-admin exclusives.
        $permissionsAccountOwner = DB::table('permissions')->select('uuid AS permission_id')
            ->whereNotIn('name', $permissionsOnlySuperAdmin)
            ->get()
            ->map(function ($x) {
                $array = (array) $x;
                $array['role_id'] = Role::ACCOUNT_OWNER;

                return $array;
            })
            ->toArray();

        if (! empty($permissionsAccountOwner)) {
            \DB::table('role_has_permissions')->insert($permissionsAccountOwner);
        }

        // Company admin: operational — can read company but not mutate billing/roles/modules.
        $companyAdminExcluded = array_merge($permissionsOnlySuperAdmin, [
            'read.audit',   'create.audit',   'update.audit',   'delete.audit',   'restore.audit',   'report.audit',
            'read.role',    'create.role',    'update.role',    'delete.role',    'restore.role',    'report.role',
            'read.permission', 'create.permission', 'update.permission', 'delete.permission', 'restore.permission', 'report.permission',
            'read.module',  'create.module',  'update.module',  'delete.module',  'restore.module',  'report.module',
            'manage.subscription',
            'create.company', 'update.company', 'delete.company', 'restore.company', 'report.company',
        ]);

        $permissionsCompanyAdmin = DB::table('permissions')->select('uuid AS permission_id')
            ->whereNotIn('name', $companyAdminExcluded)
            ->get()
            ->map(function ($x) {
                $array = (array) $x;
                $array['role_id'] = Role::COMPANY_ADMIN;

                return $array;
            })
            ->toArray();

        if (! empty($permissionsCompanyAdmin)) {
            \DB::table('role_has_permissions')->insert($permissionsCompanyAdmin);
        }

        // RH: RRHH operational — no billing, audit, roles, email, company, tenants, or users.
        $rhExcluded = array_merge($permissionsOnlySuperAdmin, [
            'read.audit',   'create.audit',   'update.audit',   'delete.audit',   'restore.audit',   'report.audit',
            'read.role',    'create.role',    'update.role',    'delete.role',    'restore.role',    'report.role',
            'read.permission', 'create.permission', 'update.permission', 'delete.permission', 'restore.permission', 'report.permission',
            'read.module',  'create.module',  'update.module',  'delete.module',  'restore.module',  'report.module',
            'manage.subscription',
            'read.email',   'create.email',   'update.email',   'delete.email',   'restore.email',   'report.email',
            'read.company', 'create.company', 'update.company', 'delete.company', 'restore.company', 'report.company',
            'read.tenant',  'create.tenant',  'update.tenant',  'delete.tenant',  'restore.tenant',  'report.tenant',
            'read.user',    'create.user',    'update.user',    'delete.user',    'restore.user',    'report.user',
        ]);

        $permissionsRH = DB::table('permissions')->select('uuid AS permission_id')
            ->whereNotIn('name', $rhExcluded)
            ->get()
            ->map(function ($x) {
                $array = (array) $x;
                $array['role_id'] = Role::RH;

                return $array;
            })
            ->toArray();

        if (! empty($permissionsRH)) {
            \DB::table('role_has_permissions')->insert($permissionsRH);
        }

        $this->seedKivo7RolePermissions($permissionsOnlySuperAdmin);
    }

    /**
     * Assign realistic per-role permission sets to the 11 Kivo7 residential
     * complex roles.
     *
     * Without this, a real login for e.g. "treasurer" yields a Sanctum token with
     * ZERO abilities (AuthController::getPermissions derives token abilities from
     * the user's role permissions) -> every gate 403 -> the app is unusable with
     * real users. This is the integration headline fix: real logins now carry real
     * abilities, and the E2E suite runs on these seeded role permissions (no test
     * workaround).
     *
     * @param  array<int, string>  $superAdminExclusives  platform-only permissions never granted to a tenant role
     */
    private function seedKivo7RolePermissions(array $superAdminExclusives): void
    {
        // Verb expander: turn an entity list + verb list into permission names.
        $perms = static function (array $entities, array $verbs): array {
            $out = [];
            foreach ($entities as $entity) {
                foreach ($verbs as $verb) {
                    $out[] = "{$verb}.{$entity}";
                }
            }

            return $out;
        };

        $crud = ['read', 'create', 'update', 'delete', 'restore', 'report'];
        $writeNoDelete = ['read', 'create', 'update', 'report'];
        $readOnly = ['read'];

        // ── Catalogs every operational role reads (status/type lookups) ────────
        $catalogs = [
            'unit_type', 'unit_status', 'unit_relationship', 'unit_feature',
            'charge_status', 'payment_status', 'charge_batch_status', 'charge_concept_category',
            'amount_mode', 'surcharge_mode', 'periodicity', 'payment_method',
            'expense_status', 'beneficiary_type', 'expense_category',
            'bank_account_type', 'bank_account_purpose',
            'provider_type', 'provider_service_type',
            'community_project_status', 'community_project_category', 'participation_type', 'project_contribution_status',
            'announcement_type', 'announcement_priority', 'announcement_status',
            'chat_channel_type',
            'visitor_motive', 'visitor_status', 'qr_type', 'access_entry_type', 'access_verification', 'blacklist_type',
            'board_role', 'board_period_status', 'employee_position', 'pay_frequency', 'employee_shift',
            // Portería — Package catalogs (Module 15)
            'package_status', 'package_carrier', 'package_type',
            // Incidencias — Complaint catalogs
            'complaint_status', 'complaint_category', 'complaint_priority',
            // Home: every operational role can open the dashboard module (data inside
            // is guarded by its own entity permissions, e.g. read.charge).
            'dashboard',
        ];

        // ── Functional clusters ────────────────────────────────────────────────
        $housing = ['tower', 'section', 'unit', 'vehicle', 'unit_user'];
        // Housing set WITHOUT unit writes: unit create/update is Admin-only (spec §6.2).
        // The president manages towers/sections/vehicles/unit_user but only READS units.
        $housingNoUnitWrite = ['tower', 'section', 'vehicle', 'unit_user'];
        $boardMgmt = ['board_period', 'board_member'];
        $collections = ['charge_concept', 'charge_policy', 'charge_batch', 'charge', 'payment', 'payment_application', 'credit_balance'];
        $providers = ['provider'];
        $projects = ['community_project', 'project_contribution', 'project_comment'];
        $announcements = ['announcement'];
        // Amenity Reservations (Module 16, flag-gated behind amenity_reservations.enabled
        // until S17). Treated like Projects (a board-managed common-area domain with an
        // approval workflow): writeNoDelete for board roles, readOnly for oversight,
        // delete stays Admin/account_owner-only (via the whereNotIn grant below).
        $amenities = ['amenity', 'amenity_reservation'];
        $chat = ['chat_channel', 'chat_message', 'chat_channel_member'];
        // Posting + membership WITHOUT channel administration: §13.4.3 limits custom
        // channel creation to administrator/president/secretary.
        $chatParticipation = ['chat_message', 'chat_channel_member'];
        $access = ['visitor', 'access_log', 'blacklist_entry'];
        $reports = ['report', 'scheduled_report'];

        // =====================================================================
        // administrator — full operational access (everything account.owner has,
        // i.e. all tenant permissions EXCEPT platform/super-admin exclusives).
        // =====================================================================
        $administrator = DB::table('permissions')
            ->whereNotIn('name', $superAdminExclusives)
            ->pluck('name')
            ->all();

        // =====================================================================
        // president — board/finance oversight: broad read, board management,
        // approve/cancel charges, publish announcements, manage units/people.
        // =====================================================================
        $president = array_merge(
            $perms($catalogs, $readOnly),
            // Housing §6.2: writes towers/sections/vehicles/unit_user and EDITS units
            // ('Editar datos de unidad: Pres ✅'); creating units, changing owners and
            // marking inactive stay Admin-only.
            $perms($housingNoUnitWrite, $writeNoDelete),
            $perms(['unit'], ['read', 'update', 'report']),
            // Board: president manages board_member fully, but STARTING a period
            // (create.board_period) is Admin-only (spec §7.2) — so board_period gets
            // every verb EXCEPT create.
            $perms(['board_member'], $crud),
            $perms(['board_period'], ['read', 'update', 'delete', 'restore', 'report']),
            // Staff §7.2: reads the directory and edits during their active period
            // (the period-window check is application-level); hiring/firing is Admin-only.
            $perms(['collaborator'], ['read', 'update', 'report']),
            $perms($collections, ['read', 'create', 'update', 'report']),
            // §8.2: discount/condonación AND charge cancellation are President/Admin
            // ONLY (NOT treasurer) — dedicated abilities, not update.charge.
            ['discount.charge', 'cancel.charge'],
            // Treasury §9.2: registers/edits/voids expenses; bank accounts are
            // Admin-only to create — president only reads them.
            $perms(['expense'], $writeNoDelete),
            ['void.expense'],
            $perms(['bank_account'], $readOnly),
            // Providers §10.2: create/edit ✅; delete is Admin-only.
            $perms($providers, $writeNoDelete),
            $perms($projects, $writeNoDelete),
            $perms($amenities, $writeNoDelete),
            $perms($announcements, $crud),
            $perms($chat, $writeNoDelete),
            // Access §14.2: generates visitor QRs, reads the full log, and manages the
            // blacklist ('Listas negras: Pres ✅'); the log itself stays immutable.
            $perms(['visitor'], ['read', 'create', 'report']),
            $perms(['access_log'], ['read', 'report']),
            $perms(['blacklist_entry'], $writeNoDelete),
            // Reports §15.2: generates/exports AND schedules automatic reports.
            $perms(['report'], $readOnly),
            $perms(['scheduled_report'], $crud),
            // Tableros (Module 14): finance oversight role — full read + report.
            $perms(['report_pnl', 'report_treasury', 'report_expenses', 'report_collections', 'report_projects'], ['read', 'report']),
            $perms(['user', 'user_profile'], ['read']),
            $perms(['audit'], ['read']),
            $perms(['configuration'], ['read']),
            // Portería: president has supervision-only access — reads the cluster list and
            // aging report, but does NOT hold create.package (the staff marker), so their
            // token passes the scopeToResidentUnitsIfNeeded read.report_package bypass and
            // they can also call GET /my-packages for their own unit's parcels.
            $perms(['package'], ['read', 'report']),
            $perms(['report_package'], ['read', 'report']),
            // Incidencias: president is both a reporter and a manager by config default.
            $perms(['complaint'], $writeNoDelete),
        );

        // =====================================================================
        // treasurer — collections + expense capture + providers; reports.
        // NOT board management, NOT bank accounts, NOT staff management, and no
        // dedicated control abilities (cancel.charge / void.expense / discount.charge).
        // =====================================================================
        $treasurer = array_merge(
            $perms($catalogs, $readOnly),
            // Housing §6.2/§6.6: reads the structure and manages person↔unit
            // associations (the renter-change flow is executed by the treasurer).
            $perms(['tower', 'section', 'unit', 'vehicle'], $readOnly),
            $perms(['unit_user'], $writeNoDelete),
            $perms($collections, $crud),
            // Treasury §9.2: registers/edits expenses ('Editar egreso: Tes ⚠️', the
            // same-day window is application-level); voiding is President/Admin only
            // and bank accounts are Admin-only to manage — treasurer just reads them.
            $perms(['expense'], $writeNoDelete),
            $perms(['bank_account'], $readOnly),
            // Providers §10.2: create/edit ⚠️ (granted directly, v1 pattern); delete
            // is Admin-only.
            $perms($providers, $writeNoDelete),
            // Staff §7.2: 'Ver directorio personal: Tes ✅' — read only; hiring,
            // editing and firing staff are explicitly denied to the treasurer.
            $perms(['collaborator'], $readOnly),
            // Board §7.2: the board directory is public to every role.
            $perms($boardMgmt, $readOnly),
            // Projects §11.2: 'Crear proyecto: Tes ✅', 'Marcar como ejecutado: Tes ✅'.
            $perms($projects, $writeNoDelete),
            // Amenity Reservations: the treasurer validates the earmarked reservation
            // payment (payment/{id}/validate, wired in S8) — same writeNoDelete tier.
            $perms($amenities, $writeNoDelete),
            $perms($reports, $writeNoDelete),
            $perms(['scheduled_report'], $crud),
            // Tableros (Module 14): treasurer has full finance data visibility.
            $perms(['report_pnl', 'report_treasury', 'report_expenses', 'report_collections', 'report_projects'], ['read', 'report']),
            // Portería: treasurer reads packages as part of cluster operations visibility.
            $perms(['package'], ['read', 'report']),
            $perms(['report_package'], ['read', 'report']),
            // Announcements §12.2: full authoring for the treasurer (own-only edit /
            // delete scoping is application-level).
            $perms($announcements, $crud),
            // Chat §13.4.3: participates but cannot create/administer channels.
            $perms($chatParticipation, $writeNoDelete),
            $perms(['chat_channel'], $readOnly),
            // Access §14.2: generates visitor QRs and reads the full access log.
            $perms(['visitor'], ['read', 'create', 'report']),
            $perms(['access_log'], ['read', 'report']),
            $perms(['user', 'user_profile'], ['read']),
            $perms(['configuration'], ['read']),
            // Incidencias: treasurer is both a reporter and a manager by config default.
            $perms(['complaint'], $writeNoDelete),
        );

        // =====================================================================
        // secretary — announcements + board directory read + documents;
        // resident-level finance read.
        // =====================================================================
        $secretary = array_merge(
            $perms($catalogs, $readOnly),
            $perms($housing, $readOnly),
            $perms($announcements, $crud),
            $perms($boardMgmt, $readOnly),
            $perms($projects, $writeNoDelete),
            $perms($amenities, $writeNoDelete),
            $perms($chat, $writeNoDelete),
            $perms($access, $readOnly),
            $perms(['charge', 'payment_application', 'credit_balance'], $readOnly),
            $perms(['user', 'user_profile'], ['read']),
            // Incidencias: secretary is both a reporter and a manager by config default.
            $perms(['complaint'], $writeNoDelete),
        );

        // =====================================================================
        // vocal — announcements (own authoring) + board read + resident-level.
        // =====================================================================
        $vocal = array_merge(
            $perms($catalogs, $readOnly),
            $perms($housing, $readOnly),
            // §12.2: vocal creates, edits/deletes their OWN announcements and schedules
            // publication (own-only scoping is application-level); marking urgent stays
            // out of reach at that same level.
            $perms($announcements, $crud),
            $perms($boardMgmt, $readOnly),
            $perms($projects, $writeNoDelete),
            $perms($amenities, $writeNoDelete),
            // Chat §13.4.3: participates but cannot create/administer channels.
            $perms($chatParticipation, $writeNoDelete),
            $perms(['chat_channel'], $readOnly),
            $perms(['charge', 'payment_application', 'credit_balance'], $readOnly),
            $perms(['user_profile'], ['read']),
            // Incidencias: vocal is both a reporter and a manager by config default.
            $perms(['complaint'], $writeNoDelete),
        );

        // =====================================================================
        // oversight_committee — reads finances + reports + audit; NO money write.
        // =====================================================================
        $oversightCommittee = array_merge(
            $perms($catalogs, $readOnly),
            $perms($housing, $readOnly),
            $perms($collections, $readOnly),
            $perms(['expense', 'bank_account'], $readOnly),
            $perms($providers, $readOnly),
            $perms($projects, $readOnly),
            $perms($amenities, $readOnly),
            // Oversight committee members can also be unit owners: grant them the
            // same scoped reserve-for-own-unit ability residents hold. ONLY the
            // create ability — update/delete/restore/report on amenity_reservation
            // and every write on amenity itself stay board-managed (writeNoDelete
            // tier), so oversight cannot approve/reject/cancel others' bookings or
            // manage the amenity catalog.
            ['create.amenity_reservation'],
            $perms($boardMgmt, $readOnly),
            $perms($reports, $readOnly),
            // Tableros (Module 14): oversight committee reads all financial dashboards.
            $perms(['report_pnl', 'report_treasury', 'report_expenses', 'report_collections', 'report_projects'], ['read', 'report']),
            // Portería: oversight committee audits package activity.
            $perms(['package'], ['read', 'report']),
            $perms(['report_package'], ['read', 'report']),
            $perms($announcements, $readOnly),
            // Chat §13.4.3: participates but cannot create/administer channels.
            $perms($chatParticipation, $writeNoDelete),
            $perms(['chat_channel'], $readOnly),
            $perms(['audit'], ['read', 'report']),
            $perms(['user', 'user_profile'], ['read']),
            // Incidencias: oversight committee is both a reporter and a manager by config
            // default (all 5 board roles).
            $perms(['complaint'], $writeNoDelete),
        );

        // =====================================================================
        // security — access control + package intake; nothing financial.
        // =====================================================================
        $security = array_merge(
            ['read.dashboard'],
            $perms(['visitor_motive', 'visitor_status', 'qr_type', 'access_entry_type', 'access_verification', 'blacklist_type'], $readOnly),
            $perms($housing, $readOnly),
            $perms(['visitor'], ['read']),
            $perms(['access_log'], ['read', 'create', 'update', 'report']),
            // §14.2 'Listas negras (bloquear): Vig ❌' — the guard checks the list at
            // the gate but cannot block/unblock.
            $perms(['blacklist_entry'], ['read']),
            $perms($announcements, $readOnly),
            $perms($chatParticipation, ['read', 'create']),
            $perms(['chat_channel'], $readOnly),
            $perms(['user_profile'], ['read', 'update']),
            // Portería — guards register and hand over packages (primary operators).
            $perms(['package'], ['read', 'create', 'update', 'report']),
            $perms(['package_status', 'package_carrier', 'package_type'], $readOnly),
        );

        // =====================================================================
        // operations_staff — minimal: own profile, read announcements/chat.
        // =====================================================================
        $operationsStaff = array_merge(
            ['read.dashboard'],
            $perms($announcements, $readOnly),
            $perms(['announcement_type', 'announcement_priority', 'announcement_status'], $readOnly),
            $perms($chatParticipation, ['read', 'create']),
            $perms(['chat_channel', 'chat_channel_type'], $readOnly),
            $perms(['user_profile'], ['read', 'update']),
        );

        // =====================================================================
        // resident — read OWN data + announcements + chat in resident channels +
        // generate own visitor QR + read own reports. NO board/finance write, no
        // broad read.payment (statement is gated to OWN unit via the pivot), no
        // neighbor data writes.
        // =====================================================================
        $resident = [
            'read.dashboard',
            'read.unit', 'read.unit_type', 'read.unit_status', 'read.unit_relationship',
            'read.unit_user', 'read.vehicle', 'read.tower', 'read.section',
            // §7.2/§7.3.3: the board directory is public to every resident.
            'read.board_period', 'read.board_member', 'read.board_role', 'read.board_period_status',
            'read.announcement', 'read.announcement_type', 'read.announcement_priority', 'read.announcement_status',
            'read.community_project', 'read.community_project_status', 'read.community_project_category',
            'read.participation_type', 'read.project_contribution', 'read.project_contribution_status',
            'read.project_comment', 'create.project_comment',
            // Amenity Reservations: residents read the amenity catalog and create their
            // OWN reservations (the endpoint scopes creation to the resident's units).
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
            'read.chat_channel', 'read.chat_channel_type', 'read.chat_message', 'read.chat_channel_member',
            'create.chat_message',
            'read.visitor', 'create.visitor', 'read.visitor_motive', 'read.qr_type',
            'read.charge', 'read.payment_application', 'read.credit_balance',
            // §8.2: a resident may capture (submit) a payment for their OWN unit. This is a
            // SCOPED submission ability (the endpoint also enforces unit ownership via the
            // pivot) — NOT blanket create.payment, so residents get no treasurer powers.
            'create.payment_submission',
            // §18.3.2: read-only on profiles. update.user_profile would let a resident
            // PATCH any neighbor's PII through the unscoped Orion resource; own-profile
            // editing returns with the user-scoped endpoint.
            'read.user_profile',
            // Portería: residents read their own unit's packages (query scoped in PackageController).
            'read.package', 'read.package_status',
        ];

        // =====================================================================
        // renter — like resident but MORE restricted: read-mostly, no writes that
        // imply ownership/membership (no project comments, no chat posting, no
        // visitor QR generation). §8.2 marks renter "capturar pago propio" as ⚠️
        // (admin-discretionary); v1 grants the same scoped submission ability as a
        // resident (still gated to their own unit by the pivot).
        // =====================================================================
        $renter = [
            'read.dashboard',
            'read.unit', 'read.unit_type', 'read.unit_status', 'read.unit_relationship',
            'read.unit_user', 'read.vehicle', 'read.tower', 'read.section',
            // §7.2/§7.3.3: the board directory is public to renters too.
            'read.board_period', 'read.board_member', 'read.board_role', 'read.board_period_status',
            'read.announcement', 'read.announcement_type', 'read.announcement_priority', 'read.announcement_status',
            'read.community_project', 'read.community_project_status', 'read.community_project_category',
            // Amenity Reservations: same scoped access as residents (own-unit reservations).
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
            'read.chat_channel', 'read.chat_channel_type', 'read.chat_message', 'read.chat_channel_member',
            'read.visitor', 'read.visitor_motive', 'read.qr_type',
            'read.charge', 'read.payment_application', 'read.credit_balance',
            'create.payment_submission',
            // §18.3.2: read-only on profiles (see resident note).
            'read.user_profile',
            // Portería: renters read their own unit's packages (query scoped in PackageController).
            'read.package', 'read.package_status',
        ];

        $roleSets = [
            Role::ADMINISTRATOR => $administrator,
            Role::PRESIDENT => $president,
            Role::TREASURER => $treasurer,
            Role::SECRETARY => $secretary,
            Role::VOCAL => $vocal,
            Role::OVERSIGHT_COMMITTEE => $oversightCommittee,
            Role::SECURITY => $security,
            Role::OPERATIONS_STAFF => $operationsStaff,
            Role::RESIDENT => $resident,
            Role::RENTER => $renter,
        ];

        // Map permission name -> uuid once, then build role_has_permissions rows.
        // Unknown names (a role asking for a permission that does not exist) are
        // silently skipped — only real permissions are linked.
        $permissionIds = DB::table('permissions')->pluck('uuid', 'name');

        $rows = [];
        foreach ($roleSets as $roleId => $names) {
            foreach (array_unique($names) as $name) {
                $permissionId = $permissionIds[$name] ?? null;
                if ($permissionId === null) {
                    continue;
                }

                $rows[] = [
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ];
            }
        }

        if (! empty($rows)) {
            foreach (array_chunk($rows, 500) as $chunk) {
                \DB::table('role_has_permissions')->insert($chunk);
            }
        }
    }
}
