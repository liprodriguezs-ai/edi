<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\BlacklistType;
use Modules\Access\Models\QrType;
use Modules\Access\Models\VisitorMotive;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Board\Models\BoardRole;
use Modules\Chat\Models\ChatChannelType;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Providers\Models\ProviderServiceType;
use Modules\Providers\Models\ProviderType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Models\ExpenseStatus;
use Modules\UserProfile\Models\UserProfile;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\TestCase;

/**
 * Role / permission boundary matrix for "Privada Los Encinos".
 *
 * Each acting person LOGS IN for real and acts with their real token abilities.
 * Asserts the "movimientos con cada rol": every role CAN do its own actions and
 * CANNOT do another role's — including the worst escalation case (a resident
 * trying to self-grant a board role).
 */
final class RolePermissionBoundaryTest extends TestCase
{
    use ClusterScenario;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, mixed> */
    private array $scn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scn = $this->buildScenario();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Worst escalation — resident cannot start a board period / self-grant a role
    // =========================================================================

    public function test_resident_cannot_start_board_period_and_no_role_is_granted(): void
    {
        $tenant = $this->scn['tenant'];
        $patricia = $this->scn['users']['patricia'];

        $presidentRoleId = $this->inTenant($tenant, fn () => BoardRole::PRESIDENT);

        $token = $this->login($this->scn['emails']['patricia']);

        $this->as($token, $tenant)->postJson('/api/board_period/start', [
            'name' => 'Autogolpe',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'auto_close_current' => false,
            'members' => [
                ['user_id' => $patricia->id, 'board_role_id' => $presidentRoleId, 'unit_id' => null],
            ],
        ])->assertStatus(403);

        // CRITICAL: no Spatie role was granted to the attacker.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $fresh = User::withoutGlobalScopes()->find($patricia->id);
        $this->assertFalse($fresh->hasRole('president'), 'A blocked board start must NOT grant any role');
    }

    // =========================================================================
    // Statements — resident reads OWN, not the neighbor's; admin reads any
    // =========================================================================

    public function test_resident_reads_own_statement_but_not_a_neighbors(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        $token = $this->login($this->scn['emails']['patricia']);

        // Patricia owns Depto 102 -> 200.
        $this->as($token, $tenant)->getJson("/api/unit/{$u['depto102']->id}/statement")->assertStatus(200);

        // Casa 1 is Roberto's -> 403.
        $this->as($token, $tenant)->getJson("/api/unit/{$u['casa1']->id}/statement")->assertStatus(403);
    }

    public function test_treasurer_reads_any_unit_statement(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        $token = $this->login($this->scn['emails']['carmen']);

        $this->as($token, $tenant)->getJson("/api/unit/{$u['casa1']->id}/statement")->assertStatus(200);
        $this->as($token, $tenant)->getJson("/api/unit/{$u['depto201']->id}/statement")->assertStatus(200);
    }

    // =========================================================================
    // Reports / delinquency — resident 403, treasurer & admin 200
    // =========================================================================

    public function test_resident_cannot_pull_reports_or_delinquency(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['patricia']);

        $this->as($token, $tenant)->getJson('/api/delinquency')->assertStatus(403);
        $this->as($token, $tenant)->getJson('/api/reports/delinquency')->assertStatus(403);
        $this->as($token, $tenant)->getJson('/api/reports/account-balances')->assertStatus(403);
    }

    public function test_treasurer_and_admin_can_pull_reports(): void
    {
        $tenant = $this->scn['tenant'];

        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->getJson('/api/delinquency')->assertStatus(200);
        $this->as($carmen, $tenant)->getJson('/api/reports/delinquency')->assertStatus(200);

        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->getJson('/api/reports/account-balances')->assertStatus(200);
    }

    // =========================================================================
    // Payments — treasurer can register; renter cannot
    // =========================================================================

    public function test_treasurer_can_register_payment_but_renter_cannot(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        // Arrange: a charge + a bank account (via the treasurer).
        $carmen = $this->login($this->scn['emails']['carmen']);

        $catId = $this->inTenant($tenant, fn () => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $modeId = $this->inTenant($tenant, fn () => AmountMode::FIXED_REQUIRED);
        $monthlyId = $this->inTenant($tenant, fn () => Periodicity::MONTHLY);

        $concept = $this->as($carmen, $tenant)->postJson('/api/charge_concept', [
            'code' => 'MANT_BND', 'name' => 'Mantenimiento',
            'charge_concept_category_id' => $catId, 'is_active' => true,
        ])->assertStatus(201)->json('data');

        // The policy carries its INITIAL (template) batch on create (target + dates).
        $this->as($carmen, $tenant)->postJson('/api/charge_policy', [
            'charge_concept_id' => $concept['id'], 'name' => 'Cuota mensual',
            'amount_mode_id' => $modeId, 'periodicity_id' => $monthlyId,
            'fixed_amount' => 120000, 'grace_days' => 5,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'is_active' => true,
            'target_type' => 'all', 'issued_at' => '2026-05-20', 'due_date' => '2026-05-25',
        ])->assertStatus(201)->json('data');

        // Bank accounts are Admin-only to create (spec §9.2) — Laura opens it.
        $bank = $this->createBankAccount($tenant, 'Op');

        $methodCash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        // Treasurer registers a payment -> 201.
        $this->as($carmen, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['casa1']->id, 'paid_at' => '2026-06-02', 'total_amount' => 120000,
            'payment_method_id' => $methodCash, 'bank_account_id' => $bank['id'],
        ])->assertStatus(201);

        // Renter (Luis) tries to register a payment -> 403 (no create.payment).
        $luis = $this->login($this->scn['emails']['luis']);
        $this->as($luis, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['casa3']->id, 'paid_at' => '2026-06-02', 'total_amount' => 120000,
            'payment_method_id' => $methodCash, 'bank_account_id' => $bank['id'],
        ])->assertStatus(403);
    }

    // =========================================================================
    // Charge cancel — president can; resident cannot
    // =========================================================================

    public function test_president_can_cancel_a_charge_but_resident_cannot(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        // Grant the president their board role through the real board-start flow so
        // president perms come from the role, like production.
        $this->grantBoardSeats($tenant);

        // Arrange a charge.
        $carmen = $this->login($this->scn['emails']['carmen']);
        $catId = $this->inTenant($tenant, fn () => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $modeId = $this->inTenant($tenant, fn () => AmountMode::FIXED_REQUIRED);
        $monthlyId = $this->inTenant($tenant, fn () => Periodicity::MONTHLY);
        $concept = $this->as($carmen, $tenant)->postJson('/api/charge_concept', [
            'code' => 'MANT_CXL', 'name' => 'Mantenimiento',
            'charge_concept_category_id' => $catId, 'is_active' => true,
        ])->assertStatus(201)->json('data');
        // The periodic policy creates a DRAFT template (0 charges) + the first-period
        // clone (is_initial = false, WITH the charges) on create.
        $policy = $this->as($carmen, $tenant)->postJson('/api/charge_policy', [
            'charge_concept_id' => $concept['id'], 'name' => 'Cuota mensual',
            'amount_mode_id' => $modeId, 'periodicity_id' => $monthlyId,
            'fixed_amount' => 120000, 'grace_days' => 5,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'is_active' => true,
            'target_type' => 'all', 'issued_at' => '2026-05-20', 'due_date' => '2026-05-25',
        ])->assertStatus(201)->json('data');
        // Charges live in the first-period clone, not the template.
        $batch = $this->inTenant($tenant, fn () => ChargeBatch::where('charge_policy_id', $policy['id'])
            ->where('is_initial', false)
            ->firstOrFail()
            ->toArray());

        $chargeId = $this->inTenant($tenant, fn () => Charge::where('charge_batch_id', $batch['id'])->where('unit_id', $u['depto201']->id)->value('id'));

        // Resident (Patricia) tries to cancel -> 403.
        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->postJson("/api/charge/{$chargeId}/cancel", [
            'reason' => 'Quiero cancelar este cargo sin autorización.',
        ])->assertStatus(403);

        // President (Roberto) cancels with reason -> 200.
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson("/api/charge/{$chargeId}/cancel", [
            'reason' => 'Departamento desocupado, se exenta la cuota.',
        ])->assertStatus(200);

        $this->assertTrue((bool) $this->inTenant($tenant, fn () => Charge::find($chargeId)->is_cancelled));
    }

    // =========================================================================
    // Announcements — secretary publishes; resident & renter cannot
    // =========================================================================

    public function test_secretary_publishes_announcement_but_resident_and_renter_cannot(): void
    {
        $tenant = $this->scn['tenant'];

        $this->grantBoardSeats($tenant);

        $typeId = $this->inTenant($tenant, fn () => Catalog::id(AnnouncementType::class, 'Aviso general'));
        $normalId = $this->inTenant($tenant, fn () => AnnouncementPriority::NORMAL);
        $draftId = $this->inTenant($tenant, fn () => AnnouncementStatus::DRAFT);

        // Jorge (secretary) creates + publishes -> 201 then 200.
        $jorge = $this->login($this->scn['emails']['jorge']);
        $ann = $this->as($jorge, $tenant)->postJson('/api/announcement', [
            'title' => 'Aviso de prueba', 'content' => 'Contenido.',
            'announcement_type_id' => $typeId, 'announcement_priority_id' => $normalId,
            'announcement_status_id' => $draftId, 'target_type' => 'all', 'channel_push' => false,
        ])->assertStatus(201)->json('data');
        $this->as($jorge, $tenant)->postJson("/api/announcement/{$ann['id']}/publish")->assertStatus(200);

        // Resident (Patricia) cannot create OR publish.
        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->postJson('/api/announcement', [
            'title' => 'No autorizado', 'content' => 'x',
            'announcement_type_id' => $typeId, 'announcement_priority_id' => $normalId,
            'announcement_status_id' => $draftId, 'target_type' => 'all', 'channel_push' => false,
        ])->assertStatus(403);
        $this->as($patricia, $tenant)->postJson("/api/announcement/{$ann['id']}/publish")->assertStatus(403);

        // Renter (Luis) also cannot publish.
        $luis = $this->login($this->scn['emails']['luis']);
        $this->as($luis, $tenant)->postJson("/api/announcement/{$ann['id']}/publish")->assertStatus(403);
    }

    // =========================================================================
    // Access control — security scans QR; resident cannot scan
    // =========================================================================

    public function test_security_scans_qr_but_resident_cannot(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        // Patricia (resident) can generate a QR for her own unit -> 201.
        $patricia = $this->login($this->scn['emails']['patricia']);
        $motiveId = $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar'));
        $qrTypeId = $this->inTenant($tenant, fn () => QrType::SINGLE_ENTRY);

        $visitor = $this->as($patricia, $tenant)->postJson('/api/visitor', [
            'name' => 'Visita Test', 'unit_id' => $u['depto102']->id,
            'visitor_motive_id' => $motiveId, 'qr_type_id' => $qrTypeId, 'arrives_by_vehicle' => false,
        ])->assertStatus(201)->json('data');

        // Resident cannot scan (no create.access_log) -> 403.
        $this->as($patricia, $tenant)->postJson('/api/access/scan-qr', ['token' => $visitor['qr_token']])->assertStatus(403);

        // Security (Pedro) scans the QR -> 200.
        $pedro = $this->login($this->scn['emails']['pedro']);
        $scan = $this->as($pedro, $tenant)->postJson('/api/access/scan-qr', ['token' => $visitor['qr_token']])->assertStatus(200)->json();
        $this->assertTrue($scan['valid']);
    }

    public function test_resident_cannot_update_or_delete_an_access_log(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        // Security creates an access log (manual entry).
        $pedro = $this->login($this->scn['emails']['pedro']);
        $verificationId = $this->inTenant($tenant, fn () => AccessVerification::PHONE_CALL);
        $log = $this->as($pedro, $tenant)->postJson('/api/access/manual-entry', [
            'unit_id' => $u['casa1']->id, 'visitor_name' => 'Repartidor', 'access_verification_id' => $verificationId,
        ])->assertStatus(201)->json('data');

        // access_log has no update/destroy verbs -> PUT/DELETE are 405 even for security.
        $this->as($pedro, $tenant)->putJson("/api/access_log/{$log['id']}", ['visitor_name' => 'x'])->assertStatus(405);
        $this->as($pedro, $tenant)->deleteJson("/api/access_log/{$log['id']}")->assertStatus(405);

        // A resident cannot checkout (update.access_log) -> 403.
        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->postJson("/api/access/{$log['id']}/checkout")->assertStatus(403);
    }

    // =========================================================================
    // Renter — read-mostly: cannot create users, cannot mutate housing
    // =========================================================================

    public function test_renter_cannot_create_a_user_or_a_unit(): void
    {
        $tenant = $this->scn['tenant'];
        $luis = $this->login($this->scn['emails']['luis']);

        $this->as($luis, $tenant)->postJson('/api/user', [
            'name' => 'Intruso', 'email' => 'intruso@x.com', 'password' => 'secret1234',
        ])->assertStatus(403);

        $unitTypeId = $this->inTenant($tenant, fn () => Catalog::id(UnitType::class, 'Casa'));
        $unitStatusId = $this->inTenant($tenant, fn () => Catalog::id(UnitStatus::class, 'Activa'));
        $this->as($luis, $tenant)->postJson('/api/unit', [
            'number' => 'Casa Fantasma', 'unit_type_id' => $unitTypeId, 'unit_status_id' => $unitStatusId,
        ])->assertStatus(403);
    }

    // =========================================================================
    // Permission matrix (FIX C) — president CANNOT create a unit (Admin-only,
    // spec §6.2) nor start a board period (Admin-only, spec §7.2); admin can.
    // =========================================================================

    public function test_president_cannot_create_unit_but_admin_can(): void
    {
        $tenant = $this->scn['tenant'];

        $this->grantBoardSeats($tenant);

        $unitTypeId = $this->inTenant($tenant, fn () => Catalog::id(UnitType::class, 'Casa'));
        $unitStatusId = $this->inTenant($tenant, fn () => Catalog::id(UnitStatus::class, 'Activa'));

        // President (Roberto) cannot create a unit -> 403 (no create.unit).
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/unit', [
            'number' => 'Casa Presidente', 'unit_type_id' => $unitTypeId, 'unit_status_id' => $unitStatusId,
        ])->assertStatus(403);

        // Admin (Laura) can create the unit -> 201.
        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->postJson('/api/unit', [
            'number' => 'Casa Admin', 'unit_type_id' => $unitTypeId, 'unit_status_id' => $unitStatusId,
        ])->assertStatus(201);
    }

    public function test_president_cannot_start_board_period_but_admin_can(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['users'];

        $members = [
            ['user_id' => $u['roberto']->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
            ['user_id' => $u['carmen']->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
            ['user_id' => $u['jorge']->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
        ];

        // President (Roberto) cannot start a board period -> 403 (no create.board_period).
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/board_period/start', [
            'name' => 'Periodo del Presidente',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'auto_close_current' => false,
            'members' => $members,
        ])->assertStatus(403);

        // Admin (Laura) can start the board period -> 201.
        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->postJson('/api/board_period/start', [
            'name' => 'Periodo del Admin',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'auto_close_current' => false,
            'members' => $members,
        ])->assertStatus(201);
    }

    // =========================================================================
    // Treasury §9.2 — bank accounts are Admin-only; president registers expenses
    // =========================================================================

    public function test_bank_account_creation_is_admin_only(): void
    {
        $tenant = $this->scn['tenant'];

        $bankTypeId = $this->inTenant($tenant, fn () => BankAccountType::BANK);
        $bankPurposeId = $this->inTenant($tenant, fn () => Catalog::id(BankAccountPurpose::class, 'Operativa'));
        $payload = [
            'name' => 'Cuenta Nueva', 'bank' => 'BBVA', 'account_number' => '99', 'currency' => 'MXN',
            'initial_balance' => 0, 'is_primary' => false, 'is_active' => true,
            'bank_account_type_id' => $bankTypeId, 'bank_account_purpose_id' => $bankPurposeId,
        ];

        // Treasurer (Carmen) cannot create a bank account -> 403 ('Crear cuenta: Tes ❌').
        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->postJson('/api/bank_account', $payload)->assertStatus(403);

        // President (Roberto) cannot either -> 403 ('Crear cuenta: Pres ❌').
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/bank_account', $payload)->assertStatus(403);

        // Admin (Laura) creates it -> 201 ('Crear cuenta: Adm ✅').
        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->postJson('/api/bank_account', $payload)->assertStatus(201);
    }

    public function test_president_can_register_an_expense(): void
    {
        $tenant = $this->scn['tenant'];

        $bank = $this->createBankAccount($tenant, 'Operativa Pres');
        $categoryId = $this->inTenant($tenant, fn () => ExpenseCategory::query()->value('id'));
        $beneficiaryOther = $this->inTenant($tenant, fn () => BeneficiaryType::OTHER);
        $methodCash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        // 'Registrar egreso: Pres ✅' (spec §9.2).
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/expense', [
            'paid_at' => '2026-06-10', 'year' => 2026, 'month' => 6,
            'amount' => 50000, 'concept' => 'Cerrajería portón principal',
            'expense_category_id' => $categoryId,
            'beneficiary_type_id' => $beneficiaryOther, 'beneficiary_text' => 'Cerrajero externo',
            'payment_method_id' => $methodCash, 'bank_account_id' => $bank['id'],
        ])->assertStatus(201);
    }

    // =========================================================================
    // Providers §10.2 — president creates/edits; delete is Admin-only
    // =========================================================================

    public function test_provider_matrix_president_creates_and_only_admin_deletes(): void
    {
        $tenant = $this->scn['tenant'];

        $typeId = $this->inTenant($tenant, fn () => Catalog::id(ProviderType::class, 'Persona moral'));
        $serviceId = $this->inTenant($tenant, fn () => Catalog::id(ProviderServiceType::class, 'Jardinería'));

        // President creates a provider -> 201 ('Crear proveedor: Pres ✅').
        $roberto = $this->login($this->scn['emails']['roberto']);
        $provider = $this->as($roberto, $tenant)->postJson('/api/provider', [
            'name' => 'Jardinería El Roble', 'provider_type_id' => $typeId,
            'provider_service_type_id' => $serviceId, 'phone' => '5512345678',
            'is_recurring' => true, 'is_active' => true,
        ])->assertStatus(201)->json('data');

        // Treasurer cannot delete it -> 403 ('Eliminar proveedor: Tes ❌').
        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->deleteJson("/api/provider/{$provider['id']}")->assertStatus(403);

        // Admin deletes it -> 200 ('Eliminar proveedor: Adm ✅').
        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->deleteJson("/api/provider/{$provider['id']}")->assertStatus(200);
    }

    // =========================================================================
    // Board §7.2 — the board directory is public; staff management is Admin-only
    // =========================================================================

    public function test_board_directory_is_readable_by_resident_renter_and_treasurer(): void
    {
        $tenant = $this->scn['tenant'];

        // 'Ver directorio mesa: Resi ✅ Tes ✅' (§7.2) and 'Cualquier residente puede
        // ver este directorio' (§7.3.3).
        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->getJson('/api/board_period')->assertStatus(200);
        $this->as($patricia, $tenant)->getJson('/api/board_member')->assertStatus(200);

        $luis = $this->login($this->scn['emails']['luis']);
        $this->as($luis, $tenant)->getJson('/api/board_period')->assertStatus(200);

        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->getJson('/api/board_member')->assertStatus(200);
    }

    public function test_treasurer_reads_staff_directory_but_cannot_hire(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['users'];

        // 'Ver directorio personal: Tes ✅' (§7.2).
        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->getJson('/api/collaborator')->assertStatus(200);

        // 'Dar de alta personal: Tes ❌' (§7.2).
        $this->as($carmen, $tenant)->postJson('/api/collaborator', [
            'name' => 'Pedro', 'last_name' => 'Vargas', 'user_id' => $u['pedro']->id,
            'create_as_user' => false, 'salary_amount' => 600000,
        ])->assertStatus(403);

        // 'Ver directorio personal: Pres ✅' (§7.2).
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->getJson('/api/collaborator')->assertStatus(200);

        // 'Dar de alta personal: Adm ✅' (§7.2).
        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->postJson('/api/collaborator', [
            'name' => 'Pedro', 'last_name' => 'Vargas', 'user_id' => $u['pedro']->id,
            'create_as_user' => false, 'salary_amount' => 600000,
        ])->assertStatus(201);
    }

    // =========================================================================
    // Housing §6.2 — president edits unit data; treasurer cannot
    // =========================================================================

    public function test_president_edits_unit_data_but_treasurer_cannot(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        // 'Editar datos de unidad: Pres ✅' (§6.2).
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->patchJson("/api/unit/{$u['casa1']->id}", [
            'notes' => 'Fachada repintada por acuerdo de asamblea.',
        ])->assertStatus(200);

        // 'Editar datos de unidad: Tes ❌' (§6.2).
        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->patchJson("/api/unit/{$u['casa1']->id}", [
            'notes' => 'No autorizado.',
        ])->assertStatus(403);
    }

    // =========================================================================
    // Projects §11.2 — treasurer creates and edits projects; resident cannot
    // =========================================================================

    public function test_treasurer_creates_and_edits_a_community_project(): void
    {
        $tenant = $this->scn['tenant'];

        $categoryId = $this->inTenant($tenant, fn () => Catalog::id(CommunityProjectCategory::class, 'Obra'));
        $mandatoryId = $this->inTenant($tenant, fn () => ParticipationType::MANDATORY);
        $fixedModeId = $this->inTenant($tenant, fn () => AmountMode::FIXED_REQUIRED);
        $draftId = $this->inTenant($tenant, fn () => CommunityProjectStatus::DRAFT);

        $payload = [
            'title' => 'Impermeabilización de azoteas',
            'description' => 'Mantenimiento mayor de azoteas comunes',
            'community_project_category_id' => $categoryId,
            'participation_type_id' => $mandatoryId,
            'amount_mode_id' => $fixedModeId,
            'fixed_amount' => 50000, 'goal_amount' => 300000,
            'target_type' => 'all', 'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
            'visible_to_residents' => true,
            'community_project_status_id' => $draftId,
        ];

        // 'Crear proyecto: Tes ✅' (§11.2, Flujo A paso 1 'Tesorero crea proyecto').
        $carmen = $this->login($this->scn['emails']['carmen']);
        $project = $this->as($carmen, $tenant)->postJson('/api/community_project', $payload)
            ->assertStatus(201)->json('data');

        // 'Marcar como ejecutado: Tes ✅' rides on update.community_project.
        $this->as($carmen, $tenant)->patchJson("/api/community_project/{$project['id']}", [
            'description' => 'Alcance ajustado tras cotización.',
        ])->assertStatus(200);

        // 'Crear proyecto: Resi ❌' (§11.2).
        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->postJson('/api/community_project', $payload)->assertStatus(403);
    }

    // =========================================================================
    // Announcements §12.2 — treasurer and vocal author announcements
    // =========================================================================

    public function test_treasurer_and_vocal_author_and_publish_announcements(): void
    {
        $tenant = $this->scn['tenant'];

        $typeId = $this->inTenant($tenant, fn () => Catalog::id(AnnouncementType::class, 'Aviso general'));
        $normalId = $this->inTenant($tenant, fn () => AnnouncementPriority::NORMAL);
        $draftId = $this->inTenant($tenant, fn () => AnnouncementStatus::DRAFT);

        $payload = fn (string $title) => [
            'title' => $title, 'content' => 'Contenido.',
            'announcement_type_id' => $typeId, 'announcement_priority_id' => $normalId,
            'announcement_status_id' => $draftId, 'target_type' => 'all', 'channel_push' => false,
        ];

        // 'Crear aviso: Tes ✅' + 'Programar publicación: Tes ✅' (§12.2).
        $carmen = $this->login($this->scn['emails']['carmen']);
        $treasurerAnn = $this->as($carmen, $tenant)->postJson('/api/announcement', $payload('Corte de agua'))
            ->assertStatus(201)->json('data');
        $this->as($carmen, $tenant)->postJson("/api/announcement/{$treasurerAnn['id']}/publish")->assertStatus(200);

        // 'Crear aviso: Vocal ✅' + 'Editar aviso propio: Vocal ✅' (§12.2; the
        // own-only scoping is application-level, the base abilities live here).
        $ana = $this->login($this->scn['emails']['ana']);
        $vocalAnn = $this->as($ana, $tenant)->postJson('/api/announcement', $payload('Junta vecinal'))
            ->assertStatus(201)->json('data');
        $this->as($ana, $tenant)->patchJson("/api/announcement/{$vocalAnn['id']}", [
            'content' => 'Se cambia la sede a la casa club.',
        ])->assertStatus(200);
        $this->as($ana, $tenant)->postJson("/api/announcement/{$vocalAnn['id']}/publish")->assertStatus(200);
    }

    // =========================================================================
    // Chat §13.4.3 — custom channels: only admin/president/secretary create them
    // =========================================================================

    public function test_chat_channel_creation_is_limited_to_admin_president_secretary(): void
    {
        $tenant = $this->scn['tenant'];
        $typeId = $this->inTenant($tenant, fn () => ChatChannelType::CUSTOM);

        $payload = fn (string $name) => ['name' => $name, 'chat_channel_type_id' => $typeId];

        foreach (['carmen', 'ana', 'miguel', 'pedro', 'joseLuis'] as $person) {
            $token = $this->login($this->scn['emails'][$person]);
            $this->as($token, $tenant)->postJson('/api/chat_channel', $payload("Canal de {$person}"))
                ->assertStatus(403);
        }

        $jorge = $this->login($this->scn['emails']['jorge']);
        $this->as($jorge, $tenant)->postJson('/api/chat_channel', $payload('Comisión de fiestas'))->assertStatus(201);

        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/chat_channel', $payload('Mesa ampliada'))->assertStatus(201);
    }

    // =========================================================================
    // Access §14.2 — treasurer/president generate QRs; blacklist is Pres/Adm only
    // =========================================================================

    public function test_access_matrix_qr_log_and_blacklist(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        $motiveId = $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar'));
        $qrTypeId = $this->inTenant($tenant, fn () => QrType::SINGLE_ENTRY);

        // 'Generar QR para mi visita: Tes ✅ / Pres ✅' (§14.2).
        $carmen = $this->login($this->scn['emails']['carmen']);
        $this->as($carmen, $tenant)->postJson('/api/visitor', [
            'name' => 'Proveedor de cambio', 'unit_id' => $u['casa2']->id,
            'visitor_motive_id' => $motiveId, 'qr_type_id' => $qrTypeId, 'arrives_by_vehicle' => false,
        ])->assertStatus(201);

        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/visitor', [
            'name' => 'Notario visitante', 'unit_id' => $u['casa1']->id,
            'visitor_motive_id' => $motiveId, 'qr_type_id' => $qrTypeId, 'arrives_by_vehicle' => false,
        ])->assertStatus(201);

        // 'Ver bitácora completa: Tes ✅' (§14.2).
        $this->as($carmen, $tenant)->getJson('/api/access_log')->assertStatus(200);

        // 'Listas negras (bloquear): Pres ✅, Vig ❌, Tes ❌' (§14.2).
        $blTypeId = $this->inTenant($tenant, fn () => BlacklistType::query()->value('id'));
        $blPayload = ['blacklist_type_id' => $blTypeId, 'value' => 'Vendedor agresivo', 'reason' => 'Reportes vecinales'];

        $this->as($roberto, $tenant)->postJson('/api/blacklist_entry', $blPayload)->assertStatus(201);

        $pedro = $this->login($this->scn['emails']['pedro']);
        $this->as($pedro, $tenant)->postJson('/api/blacklist_entry', $blPayload)->assertStatus(403);
        $this->as($carmen, $tenant)->postJson('/api/blacklist_entry', $blPayload)->assertStatus(403);
    }

    // =========================================================================
    // Reports §15.2 — president schedules automatic reports
    // =========================================================================

    public function test_president_schedules_an_automatic_report_but_resident_cannot(): void
    {
        $tenant = $this->scn['tenant'];

        $payload = ['report_key' => 'delinquency', 'frequency' => 'monthly', 'format' => 'pdf'];

        // 'Programar reporte automático: Pres ✅' (§15.2).
        $roberto = $this->login($this->scn['emails']['roberto']);
        $this->as($roberto, $tenant)->postJson('/api/scheduled_report', $payload)->assertStatus(201);

        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->postJson('/api/scheduled_report', $payload)->assertStatus(403);
    }

    // =========================================================================
    // Privacy §18.3.2 — residents/renters can no longer edit user profiles
    // =========================================================================

    public function test_resident_and_renter_cannot_edit_user_profiles(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['users'];

        // Admin captures a profile (any profile works — the point is the verb).
        $laura = $this->login($this->scn['emails']['laura']);
        $profile = $this->as($laura, $tenant)->postJson('/api/user_profile', [
            'user_id' => $u['patricia']->id, 'address' => 'Depto 102, Torre Norte',
        ])->assertStatus(201)->json('data');

        // Residents keep read access but lose the blanket update verb that allowed
        // PATCHing any neighbor's PII is denied by TWO independent layers
        // (global-03): 403 when the seeded role lacks update.user_profile, or
        // 404 when the ability exists but the self-service scope hides the
        // foreign profile. Either way the write must not land.
        $patricia = $this->login($this->scn['emails']['patricia']);
        $this->as($patricia, $tenant)->getJson('/api/user_profile')->assertStatus(200);
        $patriciaPatch = $this->as($patricia, $tenant)->patchJson("/api/user_profile/{$profile['id']}", [
            'address' => 'Dirección manipulada',
        ]);
        $this->assertContains($patriciaPatch->status(), [403, 404]);

        $luis = $this->login($this->scn['emails']['luis']);
        $luisPatch = $this->as($luis, $tenant)->patchJson("/api/user_profile/{$profile['id']}", [
            'address' => 'Dirección manipulada',
        ]);
        $this->assertContains($luisPatch->status(), [403, 404]);

        $intact = $this->inTenant($tenant, fn () => UserProfile::find($profile['id']));
        $this->assertNotSame('Dirección manipulada', $intact->address);
    }

    // =========================================================================
    // Central catalogs — read-only for every tenant role, including the admin
    // (writes are platform/super-admin exclusive; one row serves ALL clusters)
    // =========================================================================

    public function test_central_catalogs_are_read_only_and_tenant_catalogs_are_manageable(): void
    {
        $tenant = $this->scn['tenant'];

        $laura = $this->login($this->scn['emails']['laura']);

        // Reading catalogs still works for everyone.
        $this->as($laura, $tenant)->getJson('/api/charge_status')->assertStatus(200);

        // CENTRALES (FSM/comportamiento): rechazados — una fila sirve a TODOS los clústeres.
        $pendingId = $this->inTenant($tenant, fn () => ChargeStatus::PENDING);
        $this->as($laura, $tenant)->patchJson("/api/charge_status/{$pendingId}", ['name' => 'Hackeado'])->assertStatus(403);

        // AUTOADMINISTRABLES (tenant-scoped): el admin del clúster SÍ los gestiona,
        // acotado a SUS filas por TenantScope.
        $created = $this->as($laura, $tenant)->postJson('/api/unit_status', ['name' => 'Estado pirata']);
        $created->assertStatus(201);
        $this->as($laura, $tenant)->deleteJson('/api/unit_status/'.$created->json('data.id'))->assertStatus(200);

        $individualId = $this->inTenant($tenant, fn () => Catalog::id(ProviderType::class, 'Persona física'));
        $this->as($laura, $tenant)->deleteJson("/api/provider_type/{$individualId}")->assertStatus(200);

        $carmen = $this->login($this->scn['emails']['carmen']);
        $paidId = $this->inTenant($tenant, fn () => ExpenseStatus::PAID);
        $this->as($carmen, $tenant)->patchJson("/api/expense_status/{$paidId}", ['name' => 'Pagadísimo'])->assertStatus(403);

        // The audit log is immutable (§18.2.1): the write routes do not even
        // exist anymore — POST /api/audit answers 405 Method Not Allowed.
        $this->as($laura, $tenant)->postJson('/api/audit', [])->assertStatus(405);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Bank accounts are Admin-only to create (spec §9.2) — open one as Laura.
     *
     * @return array<string, mixed>
     */
    private function createBankAccount(Tenant $tenant, string $name): array
    {
        $bankTypeId = $this->inTenant($tenant, fn () => BankAccountType::BANK);
        $bankPurposeId = $this->inTenant($tenant, fn () => Catalog::id(BankAccountPurpose::class, 'Operativa'));

        $laura = $this->login($this->scn['emails']['laura']);

        return $this->as($laura, $tenant)->postJson('/api/bank_account', [
            'name' => $name, 'bank' => 'BBVA', 'account_number' => '1', 'currency' => 'MXN',
            'initial_balance' => 0, 'is_primary' => true, 'is_active' => true,
            'bank_account_type_id' => $bankTypeId, 'bank_account_purpose_id' => $bankPurposeId,
        ])->assertStatus(201)->json('data');
    }

    // =========================================================================
    // Helper — grant the board seats through the real board-start flow
    // =========================================================================

    private function grantBoardSeats($tenant): void
    {
        $u = $this->scn['users'];

        // BoardRole constants ARE the central catalog UUID ids — use them directly.
        $laura = $this->login($this->scn['emails']['laura']);
        $this->as($laura, $tenant)->postJson('/api/board_period/start', [
            'name' => '2026-2027',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'auto_close_current' => false,
            'members' => [
                ['user_id' => $u['roberto']->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
                ['user_id' => $u['carmen']->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                ['user_id' => $u['jorge']->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ['user_id' => $u['ana']->id, 'board_role_id' => BoardRole::VOCAL, 'unit_id' => null],
                ['user_id' => $u['miguel']->id, 'board_role_id' => BoardRole::OVERSIGHT_COMMITTEE, 'unit_id' => null],
            ],
        ])->assertStatus(201);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
