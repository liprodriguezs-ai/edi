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
        // shared across ALL tenants). Mutating one row would hit every organization at
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
            // ChildRecord (EDI): sex/population_type are FSM-adjacent central catalogs
            // (D4 in edi-clinic-and-child-registry design) — CURP cross-check and the
            // future Secretaría export branch on them, so writes stay super-admin only.
            'sex', 'population_type',
            // Screening application (edi-screening-application D1): the 4 new
            // central catalogs (FSM/behavior-branching rows) — mutations become
            // super-admin exclusives, tenant roles keep read via $commonPermissions.
            'screening_status', 'screening_situation', 'finding_type', 'confirmatory_test_result',
            // Stimulation (edi-stimulation-sessions REQ-XC-002): the 3 central
            // catalogs — mutations become super-admin exclusives, tenant roles
            // keep read via $commonPermissions.
            'session_section', 'stimulation_area', 'attendance_payment_status',
            // FollowUp (edi-followup-and-referral D6): the 2 central catalogs
            // (conduct type + lifecycle FSM) — mutations become super-admin
            // exclusives, tenant roles keep read via $commonPermissions.
            'follow_up_type', 'follow_up_status',
            // Agenda (odd/tasks/edi-agenda.md): lifecycle FSM + appointment kinds.
            'appointment_status', 'appointment_type',
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
            // Instrument (EDI instrument catalog, D22/D23): platform content,
            // read-only API (index/show only), super-admin-exclusive. Content
            // enters ONLY via InstrumentSeeder -- no tenant role gets ANY verb,
            // including read, because the inspector is a super-admin tool; the
            // Screening module will serve tenant-facing reads through its own
            // endpoints, never by browsing the raw instrument. MUST NOT be added
            // to $mapperSelects/CatalogType (D22) -- deliberately absent.
            'read.instrument', 'create.instrument', 'update.instrument', 'delete.instrument', 'restore.instrument', 'report.instrument',
        ], $centralCatalogWrites);

        // Base permission catalog consumed ONLY by the super-admin grant below
        // (exclusives + this list). Tenant roles receive their permissions via the
        // whereNotIn queries / the EDI role sets, so entries here that also
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
            // ChildRecord / Clinic (edi-clinic-and-child-registry)
            'read.health_center', 'update.health_center', 'delete.health_center', 'create.health_center', 'restore.health_center', 'report.health_center',
            'read.practitioner_profile', 'update.practitioner_profile', 'delete.practitioner_profile', 'create.practitioner_profile', 'restore.practitioner_profile', 'report.practitioner_profile',
            'read.child', 'update.child', 'delete.child', 'create.child', 'restore.child', 'report.child',
            // Perinatal history has its own permission (odd/tasks/edi-agenda.md S16).
            'read.birth_record', 'update.birth_record', 'delete.birth_record', 'create.birth_record', 'restore.birth_record', 'report.birth_record',
            'read.caregiver', 'update.caregiver', 'delete.caregiver', 'create.caregiver', 'restore.caregiver', 'report.caregiver',
            'read.visit', 'update.visit', 'delete.visit', 'create.visit', 'restore.visit', 'report.visit',
            'read.sex', 'update.sex', 'delete.sex', 'create.sex', 'restore.sex', 'report.sex',
            'read.population_type', 'update.population_type', 'delete.population_type', 'create.population_type', 'restore.population_type', 'report.population_type',
            'read.caregiver_relationship', 'update.caregiver_relationship', 'delete.caregiver_relationship', 'create.caregiver_relationship', 'restore.caregiver_relationship', 'report.caregiver_relationship',
            'read.education_level', 'update.education_level', 'delete.education_level', 'create.education_level', 'restore.education_level', 'report.education_level',
            'read.occupation', 'update.occupation', 'delete.occupation', 'create.occupation', 'restore.occupation', 'report.occupation',
            // Screening application (edi-screening-application). rescore.screening
            // is a 13th standalone ability (D21/Q7) — common for now (tenant roles
            // receive it), tighten with product input in a later permissions pass.
            'read.screening', 'update.screening', 'delete.screening', 'create.screening', 'restore.screening', 'report.screening',
            'rescore.screening',
            'read.confirmatory_test', 'update.confirmatory_test', 'delete.confirmatory_test', 'create.confirmatory_test', 'restore.confirmatory_test', 'report.confirmatory_test',
            'read.screening_status', 'update.screening_status', 'delete.screening_status', 'create.screening_status', 'restore.screening_status', 'report.screening_status',
            'read.screening_situation', 'update.screening_situation', 'delete.screening_situation', 'create.screening_situation', 'restore.screening_situation', 'report.screening_situation',
            'read.finding_type', 'update.finding_type', 'delete.finding_type', 'create.finding_type', 'restore.finding_type', 'report.finding_type',
            'read.confirmatory_test_result', 'update.confirmatory_test_result', 'delete.confirmatory_test_result', 'create.confirmatory_test_result', 'restore.confirmatory_test_result', 'report.confirmatory_test_result',
            // Stimulation (edi-stimulation-sessions)
            'read.stimulation_session', 'update.stimulation_session', 'delete.stimulation_session', 'create.stimulation_session', 'restore.stimulation_session', 'report.stimulation_session',
            'read.stimulation_group', 'update.stimulation_group', 'delete.stimulation_group', 'create.stimulation_group', 'restore.stimulation_group', 'report.stimulation_group',
            'read.stimulation_dynamic', 'update.stimulation_dynamic', 'delete.stimulation_dynamic', 'create.stimulation_dynamic', 'restore.stimulation_dynamic', 'report.stimulation_dynamic',
            'read.body_zone', 'update.body_zone', 'delete.body_zone', 'create.body_zone', 'restore.body_zone', 'report.body_zone',
            'read.massage_movement', 'update.massage_movement', 'delete.massage_movement', 'create.massage_movement', 'restore.massage_movement', 'report.massage_movement',
            'read.didactic_material', 'update.didactic_material', 'delete.didactic_material', 'create.didactic_material', 'restore.didactic_material', 'report.didactic_material',
            'read.song', 'update.song', 'delete.song', 'create.song', 'restore.song', 'report.song',
            'read.stimulation_group_type', 'update.stimulation_group_type', 'delete.stimulation_group_type', 'create.stimulation_group_type', 'restore.stimulation_group_type', 'report.stimulation_group_type',
            'read.session_section', 'update.session_section', 'delete.session_section', 'create.session_section', 'restore.session_section', 'report.session_section',
            'read.stimulation_area', 'update.stimulation_area', 'delete.stimulation_area', 'create.stimulation_area', 'restore.stimulation_area', 'report.stimulation_area',
            'read.attendance_payment_status', 'update.attendance_payment_status', 'delete.attendance_payment_status', 'create.attendance_payment_status', 'restore.attendance_payment_status', 'report.attendance_payment_status',
            // FollowUp (edi-followup-and-referral)
            'read.follow_up', 'update.follow_up', 'delete.follow_up', 'create.follow_up', 'restore.follow_up', 'report.follow_up',
            'read.referral', 'update.referral', 'delete.referral', 'create.referral', 'restore.referral', 'report.referral',
            'read.referral_specialty', 'update.referral_specialty', 'delete.referral_specialty', 'create.referral_specialty', 'restore.referral_specialty', 'report.referral_specialty',
            'read.follow_up_type', 'update.follow_up_type', 'delete.follow_up_type', 'create.follow_up_type', 'restore.follow_up_type', 'report.follow_up_type',
            'read.follow_up_status', 'update.follow_up_status', 'delete.follow_up_status', 'create.follow_up_status', 'restore.follow_up_status', 'report.follow_up_status',
            // Agenda (odd/tasks/edi-agenda.md)
            'read.appointment', 'update.appointment', 'delete.appointment', 'create.appointment', 'restore.appointment', 'report.appointment',
            'read.appointment_status', 'update.appointment_status', 'delete.appointment_status', 'create.appointment_status', 'restore.appointment_status', 'report.appointment_status',
            'read.appointment_type', 'update.appointment_type', 'delete.appointment_type', 'create.appointment_type', 'restore.appointment_type', 'report.appointment_type',
            // Reports (edi-reports-and-indicators, REQ-RPT-008). Both entities
            // are common permissions here because this codebase's ONLY
            // non-super-admin tenant role is Role::ADMINISTRATOR
            // (seedEDIRolePermissions below) -- there is no separate
            // "clinical-only" tenant role to withhold the nominal/export
            // abilities from. The permission-boundary tests build a
            // purpose-built role holding ONLY read.screening_indicator to
            // prove the nominal surface stays gated independently.
            'read.screening_indicator', 'update.screening_indicator', 'delete.screening_indicator', 'create.screening_indicator', 'restore.screening_indicator', 'report.screening_indicator',
            'read.nominal_report', 'update.nominal_report', 'delete.nominal_report', 'create.nominal_report', 'restore.nominal_report', 'report.nominal_report',
            // EDI legacy parity (odd/tasks/edi-legacy-parity.md)
            'read.edi_concentrate', 'create.edi_concentrate', 'update.edi_concentrate', 'delete.edi_concentrate', 'restore.edi_concentrate', 'report.edi_concentrate',
            'read.general_report', 'create.general_report', 'update.general_report', 'delete.general_report', 'restore.general_report', 'report.general_report',
            'read.corrected_age', 'create.corrected_age', 'update.corrected_age', 'delete.corrected_age', 'restore.corrected_age', 'report.corrected_age',
            'read.nutritional_assessment', 'create.nutritional_assessment', 'update.nutritional_assessment', 'delete.nutritional_assessment', 'restore.nutritional_assessment', 'report.nutritional_assessment',
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
        $this->seedEDIRolePermissions($permissionsOnlySuperAdmin);
        $this->seedAssistantPermissions();
    }

    /**
     * Front desk (Role::ASSISTANT, odd/tasks/edi-agenda.md S14). An explicit
     * allow-list, never "everything minus": new modules stay closed to the role
     * until someone decides otherwise. Deliberately absent: create.visit (starting
     * care creates the visit and its folio), every screening/follow-up ability,
     * writes to the perinatal history, every delete, and the dashboard plus the
     * health-center / practitioner screens (S17: the menu shows only what the
     * front desk operates; the agenda reads those catalogs through /selects).
     */
    private function seedAssistantPermissions(): void
    {
        $allowed = [
            'read.notification', 'update.notification',
            'read.user_profile', 'update.user_profile',
            'read.appointment', 'create.appointment', 'update.appointment',
            'read.appointment_status', 'read.appointment_type',
            'read.child', 'create.child', 'update.child',
            'read.caregiver', 'create.caregiver', 'update.caregiver',
            // Perinatal history is clinical: read-only for the front desk (S16).
            'read.birth_record',
            'read.visit',
            'read.sex', 'read.population_type', 'read.caregiver_relationship',
            'read.education_level', 'read.occupation',
        ];

        $rows = DB::table('permissions')
            ->whereIn('name', $allowed)
            ->pluck('uuid')
            ->map(fn (string $permissionId): array => ['permission_id' => $permissionId, 'role_id' => Role::ASSISTANT])
            ->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insert($rows);
        }
    }

    /**
     * Assign the tenant-facing permission set to the organization administrator.
     *
     * Without this, a real login yields a Sanctum token with ZERO abilities
     * (AuthController::getPermissions derives token abilities from the role's
     * permissions), so every endpoint 403s.
     *
     * The template shipped ten condominium roles (president, treasurer,
     * secretary, vocal, oversight committee, security, operations staff,
     * resident, renter) each with a hand-tuned permission list. Those roles are
     * gone. EDI defines its own — applicator, supervisor, promoter, viewer — and
     * each gets its array here as the screening modules land.
     *
     * @param  string[]  $superAdminExclusives  Permissions the platform reserves for the super admin.
     */
    private function seedEDIRolePermissions(array $superAdminExclusives): void
    {
        $permissionIds = DB::table('permissions')
            ->whereNotIn('name', $superAdminExclusives)
            ->pluck('uuid', 'name');

        $rows = [];
        foreach ($permissionIds as $permissionId) {
            $rows[] = [
                'permission_id' => $permissionId,
                'role_id' => Role::ADMINISTRATOR,
            ];
        }

        if (! empty($rows)) {
            foreach (array_chunk($rows, 500) as $chunk) {
                \DB::table('role_has_permissions')->insert($chunk);
            }
        }
    }
}
