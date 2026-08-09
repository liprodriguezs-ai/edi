<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Access\Models\AccessLog;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\QrType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Models\VisitorStatus;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementRead;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardRole;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatReaction;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Unit;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Services\ContributionService;
use Modules\Providers\Models\Provider;
use Modules\Providers\Models\ProviderServiceType;
use Modules\Providers\Models\ProviderType;
use Modules\Setting\Models\Setting;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Models\ExpenseStatus;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\TestCase;

/**
 * Un mes en la vida del fraccionamiento "Privada Los Encinos".
 *
 * End-to-end integration walk exercising every Kivo7 module through the REAL
 * HTTP API with REAL logins. Each acting person carries a Sanctum token whose
 * abilities come from their actual Spatie role, so the permission gates behave
 * exactly like production.
 *
 * The narrative (asserting HTTP result AND data coherence at every step):
 *   structure -> people/units -> board period (real role grant) -> employees ->
 *   charge concept -> batch -> 6 charges -> payments (FIFO + credit) ->
 *   extraordinary single-unit fine -> bank account -> expenses (+ void) ->
 *   provider -> community project (mandatory) -> announcement (urgent) ->
 *   chat -> access (QR scan) -> reports (incl. PDF) -> configuration ->
 *   whole-system coherence reconciliation.
 */
final class ClusterLifecycleTest extends TestCase
{
    use ClusterScenario;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, mixed> */
    private array $scn;

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->scn = $this->buildScenario();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * A non-empty fake PDF receipt (non-cash payments now require a comprobante, §8.7.1 r5).
     */
    private function receiptFile(): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_e2e_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 comprobante ', 100));

        return new UploadedFile($tmpPath, 'comprobante.pdf', 'application/pdf', null, true);
    }

    // =========================================================================
    // 1. Auth + structure + me — every person logs in for real
    // =========================================================================

    public function test_01_every_person_logs_in_and_sees_the_right_tenant_and_abilities(): void
    {
        $tenant = $this->scn['tenant'];

        // Laura (admin) logs in: token carries the full operational ability set.
        $login = $this->postJson('/api/auth/login', [
            'email' => $this->scn['emails']['laura'],
            'password' => 'secret1234',
        ])->assertStatus(200);

        $data = $login->json('data');
        $this->assertSame($tenant->id, $data['tenant']['id'], 'Active tenant must be Privada Los Encinos');
        $this->assertContains('administrator', $data['roles']);
        $this->assertContains('create.payment', $data['abilities'], 'Admin token must carry operational abilities');
        $this->assertNotContains('read.tenant', $data['abilities'], 'No tenant-platform ability for a cluster admin');

        // Patricia (resident) logs in: narrow abilities only.
        $resLogin = $this->postJson('/api/auth/login', [
            'email' => $this->scn['emails']['patricia'],
            'password' => 'secret1234',
        ])->assertStatus(200);

        $resData = $resLogin->json('data');
        $this->assertContains('resident', $resData['roles']);
        $this->assertContains('read.unit', $resData['abilities']);
        $this->assertNotContains('create.payment', $resData['abilities'], 'Resident must NOT carry create.payment');
        $this->assertNotContains('read.report', $resData['abilities'], 'Resident must NOT carry read.report');

        // me works with the issued token + tenant header.
        $this->as($data['access_token'], $tenant)
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.tenant.id', $tenant->id);

        // Structure is coherent: 6 units, the right numbers, shares sum ~100%.
        $lauraToken = $this->login($this->scn['emails']['laura']);
        $units = $this->as($lauraToken, $tenant)->getJson('/api/unit?limit=50')->assertStatus(200)->json('data');
        $this->assertCount(6, $units, 'Cluster must have exactly 6 units');

        $shareSum = $this->inTenant($tenant, fn () => (float) Unit::sum('ownership_share'));
        $this->assertEqualsWithDelta(100.0, $shareSum, 0.01, 'Ownership shares must sum to ~100%');

        // Operational config persisted.
        $tenant->refresh();
        $this->assertSame('America/Mexico_City', $tenant->timezone);
        $this->assertSame('MXN', $tenant->currency);
        $this->assertSame('mixto', $tenant->type);
    }

    // =========================================================================
    // 2. Board period — Laura starts it via the API; Spatie roles are granted
    // =========================================================================

    public function test_02_admin_starts_board_period_and_system_grants_spatie_roles(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['users'];

        // BoardRole constants ARE the central catalog UUID ids — use them directly.
        $lauraToken = $this->login($this->scn['emails']['laura']);

        $resp = $this->as($lauraToken, $tenant)->postJson('/api/board_period/start', [
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
        ]);
        $resp->assertStatus(201);

        // The period is current with 5 members.
        $period = $this->inTenant($tenant, fn () => BoardPeriod::with('members')->where('name', '2026-2027')->first());
        $this->assertNotNull($period);
        $this->assertSame(5, $period->members->count());

        // CRITICAL: the system actually granted each global Spatie role.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $expected = [
            'roberto' => 'president',
            'carmen' => 'treasurer',
            'jorge' => 'secretary',
            'ana' => 'vocal',
            'miguel' => 'oversight_committee',
        ];
        foreach ($expected as $key => $slug) {
            $fresh = User::withoutGlobalScopes()->find($u[$key]->id);
            $this->assertTrue($fresh->hasRole($slug), "{$key} must have the {$slug} role after board start");
        }
    }

    // =========================================================================
    // 3. Employees — Laura (admin) registers the security guard + gardener
    // =========================================================================

    public function test_03_admin_registers_employees(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['users'];

        $lauraToken = $this->login($this->scn['emails']['laura']);

        // Pedro the security guard — linked to his app user.
        $pedroEmp = $this->as($lauraToken, $tenant)->postJson('/api/collaborator', [
            'name' => 'Pedro',
            'last_name' => 'Vargas',
            'user_id' => $u['pedro']->id,
            'create_as_user' => false,
            'salary_amount' => 600000,
        ])->assertStatus(201)->json('data');

        // José Luis the gardener.
        $jardEmp = $this->as($lauraToken, $tenant)->postJson('/api/collaborator', [
            'name' => 'José Luis',
            'last_name' => 'Hernández',
            'user_id' => $u['joseLuis']->id,
            'create_as_user' => false,
            'salary_amount' => 800000,
        ])->assertStatus(201)->json('data');

        $this->assertNotEmpty($pedroEmp['id']);
        $this->assertNotEmpty($jardEmp['id']);

        $count = $this->inTenant($tenant, fn () => Collaborator::count());
        $this->assertSame(2, $count, 'Exactly two employees registered');
    }

    // =========================================================================
    // 4. Charge concept + batch + 6 charges + payments (FIFO + credit) + fine
    // =========================================================================

    public function test_04_collections_concept_batch_payments_and_fine(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];
        $people = $this->scn['users'];

        // Carmen the treasurer creates the maintenance concept ($1,200 = 120000).
        $carmenToken = $this->login($this->scn['emails']['carmen']);

        $catId = $this->inTenant($tenant, fn () => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $modeId = $this->inTenant($tenant, fn () => AmountMode::FIXED_REQUIRED);
        $monthlyId = $this->inTenant($tenant, fn () => Periodicity::MONTHLY);

        $concept = $this->as($carmenToken, $tenant)->postJson('/api/charge_concept', [
            'code' => 'MANT_MEN',
            'name' => 'Mantenimiento mensual',
            'charge_concept_category_id' => $catId,
            'is_active' => true,
        ])->assertStatus(201)->json('data');

        $this->assertSame('MANT_MEN', $concept['code']);

        // A periodic policy creates TWO batches on create: a pure DRAFT template
        // (is_initial = true, 0 charges) the cron clones each period, PLUS the first
        // period materialized as an ACTIVATED clone (is_initial = false, WITH the 6
        // charges) anchored on valid_from (January 2026). Those first-period charges
        // are long overdue by the test's current date, so unpaid units go OVERDUE after
        // grace — populating the delinquency dashboard coherently.
        $policy = $this->as($carmenToken, $tenant)->postJson('/api/charge_policy', [
            'charge_concept_id' => $concept['id'],
            'name' => 'Cuota mensual',
            'amount_mode_id' => $modeId,
            'periodicity_id' => $monthlyId,
            'fixed_amount' => 120000,
            'grace_days' => 5,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'is_active' => true,
            'target_type' => 'all',
            'issued_at' => '2026-05-20',
            'due_date' => '2026-05-25',
        ])->assertStatus(201)->json('data');

        // Money stored as integer minor units.
        $rawFixed = $this->inTenant($tenant, fn () => (int) ChargePolicy::find($policy['id'])->getAttributes()['fixed_amount']);
        $this->assertSame(120000, $rawFixed);

        // The first-period clone batch generated on policy create (is_initial = false).
        // The template (is_initial = true) carries 0 charges; the 6 charges live here.
        $batch = $this->inTenant($tenant, fn () => ChargeBatch::where('charge_policy_id', $policy['id'])
            ->where('is_initial', false)
            ->firstOrFail()
            ->toArray());

        // 6 charges of $1,200 each.
        $charges = $this->inTenant($tenant, fn () => Charge::where('charge_batch_id', $batch['id'])->get());
        $this->assertCount(6, $charges, 'One charge per unit');
        foreach ($charges as $c) {
            $this->assertSame(120000, (int) $c->getAttributes()['expected_amount']);
        }

        $methodCash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);
        $methodTransfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        // Bank account for payments to land in — only the admin may create it (§9.2).
        $bankTypeId = $this->inTenant($tenant, fn () => BankAccountType::BANK);
        $bankPurposeId = $this->inTenant($tenant, fn () => Catalog::id(BankAccountPurpose::class, 'Operativa'));

        $bankPayload = [
            'name' => 'Cuenta Operativa BBVA',
            'bank' => 'BBVA',
            'account_number' => '01234567890',
            'currency' => 'MXN',
            'initial_balance' => 5000000,
            'is_primary' => true,
            'is_active' => true,
            'bank_account_type_id' => $bankTypeId,
            'bank_account_purpose_id' => $bankPurposeId,
        ];

        $this->as($carmenToken, $tenant)->postJson('/api/bank_account', $bankPayload)->assertStatus(403);

        $lauraToken = $this->login($this->scn['emails']['laura']);
        $bank = $this->as($lauraToken, $tenant)->postJson('/api/bank_account', $bankPayload)
            ->assertStatus(201)->json('data');

        $this->assertSame(5000000, $this->inTenant($tenant, fn () => (int) BankAccount::find($bank['id'])->getAttributes()['current_balance']));

        // Roberto (Casa 1) pays $1,200 cash -> his charge completed.
        $this->as($carmenToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['casa1']->id,
            'paid_at' => '2026-06-02',
            'total_amount' => 120000,
            'payment_method_id' => $methodCash,
            'bank_account_id' => $bank['id'],
            'payer_user_id' => $people['roberto']->id,
        ])->assertStatus(201);

        // Carmen (Casa 2) pays $1,500 -> $1,200 applied + $300 credit.
        $this->as($carmenToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['casa2']->id,
            'paid_at' => '2026-06-02',
            'total_amount' => 150000,
            'payment_method_id' => $methodCash,
            'bank_account_id' => $bank['id'],
            'payer_user_id' => $people['carmen']->id,
        ])->assertStatus(201);

        // Patricia (Depto 102) pays $1,200 by transfer -> completed.
        // Non-cash payments require a comprobante (§8.7.1 r5).
        $this->as($carmenToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['depto102']->id,
            'paid_at' => '2026-06-03',
            'total_amount' => 120000,
            'payment_method_id' => $methodTransfer,
            'bank_account_id' => $bank['id'],
            'payer_user_id' => $people['patricia']->id,
            'receipt' => $this->receiptFile(),
        ])->assertStatus(201);

        // Ana (Casa 3), Jorge (Depto 101), Miguel (Depto 201) do NOT pay.

        // Receipt numbers follow R-2026-NNNNNN.
        $receipts = $this->inTenant($tenant, fn () => Payment::orderBy('receipt_number')->pluck('receipt_number')->all());
        $this->assertCount(3, $receipts);
        foreach ($receipts as $r) {
            $this->assertMatchesRegularExpression('/^R-2026-\d{6}$/', $r);
        }

        // Casa 1 charge completed.
        $casa1Charge = $this->inTenant($tenant, fn () => Charge::where('unit_id', $u['casa1']->id)->where('charge_batch_id', $batch['id'])->first());
        $completedId = $this->inTenant($tenant, fn () => ChargeStatus::COMPLETED);
        $this->assertSame($completedId, $casa1Charge->charge_status_id);

        // Casa 2 carries a $300 (30000) credit balance.
        $casa2Credit = $this->inTenant($tenant, fn () => CreditBalance::where('unit_id', $u['casa2']->id)->first());
        $this->assertNotNull($casa2Credit);
        $this->assertSame(30000, (int) $casa2Credit->getAttributes()['amount']);

        // Bank balance = initial 50,000 + 3 confirmed payments (1200 + 1500 + 1200 = 3900).
        $bankBalance = $this->inTenant($tenant, fn () => (int) BankAccount::find($bank['id'])->getAttributes()['current_balance']);
        $this->assertSame(5000000 + 120000 + 150000 + 120000, $bankBalance);

        // Roberto (president) cancels a charge with reason -> 200.
        // Use the unpaid Depto 201 charge so cancellation is clean.
        $robertoToken = $this->login($this->scn['emails']['roberto']);
        $depto201Charge = $this->inTenant($tenant, fn () => Charge::where('unit_id', $u['depto201']->id)->where('charge_batch_id', $batch['id'])->first());
        $this->as($robertoToken, $tenant)->postJson("/api/charge/{$depto201Charge->id}/cancel", [
            'reason' => 'Departamento desocupado durante el periodo, se exenta la cuota.',
        ])->assertStatus(200);

        $depto201Charge = $this->inTenant($tenant, fn () => Charge::find($depto201Charge->id));
        $this->assertTrue((bool) $depto201Charge->is_cancelled);

        // Extraordinary single-unit fine: "Multa por ruido" $300 on Casa 3 only.
        $surchargeCat = $this->inTenant($tenant, fn () => Catalog::id(ChargeConceptCategory::class, 'Recargo'));
        $eventualId = $this->inTenant($tenant, fn () => Periodicity::EVENTUAL);

        $fineConcept = $this->as($carmenToken, $tenant)->postJson('/api/charge_concept', [
            'code' => 'MULTA_RUIDO',
            'name' => 'Multa por ruido',
            'charge_concept_category_id' => $surchargeCat,
            'is_active' => true,
        ])->assertStatus(201)->json('data');

        // A direct (one-shot) policy generates its single-unit batch immediately on
        // creation — no separate batch endpoint call needed.
        $finePolicy = $this->as($carmenToken, $tenant)->postJson('/api/charge_policy', [
            'charge_concept_id' => $fineConcept['id'],
            'name' => 'Multa por ruido',
            'amount_mode_id' => $modeId,
            'periodicity_id' => $eventualId,
            'fixed_amount' => 30000,
            'grace_days' => 0,
            'target_type' => 'manual',
            'target_selector' => ['unit_ids' => [$u['casa3']->id]],
            'issued_at' => '2026-06-04',
            'due_date' => '2026-06-20',
            'is_active' => true,
        ])->assertStatus(201)->json('data');

        $fineCharges = $this->inTenant($tenant, fn () => Charge::where('charge_policy_id', $finePolicy['id'])->get());
        $this->assertCount(1, $fineCharges, 'Fine batch affects only Casa 3');
        $this->assertSame($u['casa3']->id, $fineCharges->first()->unit_id);
        $this->assertSame(30000, (int) $fineCharges->first()->getAttributes()['expected_amount']);

        // No other unit got the fine.
        $otherFine = $this->inTenant($tenant, fn () => Charge::where('charge_policy_id', $finePolicy['id'])->where('unit_id', '!=', $u['casa3']->id)->count());
        $this->assertSame(0, $otherFine);

        // Carmen (treasurer) reads Casa 2 statement: shows the $300 credit + completed maintenance.
        $stmt = $this->as($carmenToken, $tenant)->getJson("/api/unit/{$u['casa2']->id}/statement")->assertStatus(200)->json();
        $this->assertSame('30000', $stmt['credit_balance']['amount']);
        $this->assertSame('120000', $stmt['totals']['expected']['amount']);
        $this->assertSame('120000', $stmt['totals']['paid']['amount']);
        $this->assertSame('0', $stmt['totals']['outstanding']['amount']);

        // Delinquency dashboard lists the unpaid units (Ana/Casa3, Jorge/Depto101);
        // Depto201 was cancelled so it must NOT appear.
        $deliq = $this->as($carmenToken, $tenant)->getJson('/api/delinquency')->assertStatus(200)->json();
        $debtUnitIds = array_column($deliq['data'], 'unit_id');
        $this->assertContains($u['casa3']->id, $debtUnitIds, 'Casa 3 (unpaid + fine) must be delinquent');
        $this->assertContains($u['depto101']->id, $debtUnitIds, 'Depto 101 (unpaid) must be delinquent');
        $this->assertNotContains($u['casa1']->id, $debtUnitIds, 'Casa 1 (paid) must not be delinquent');
        $this->assertNotContains($u['depto201']->id, $debtUnitIds, 'Cancelled charge must not count as debt');
    }

    // =========================================================================
    // 5. Treasury — expenses (salary + provider) + void restores balance
    // =========================================================================

    public function test_05_treasury_expenses_and_void(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];
        $people = $this->scn['users'];

        $carmenToken = $this->login($this->scn['emails']['carmen']);

        // Seed the same paid state as test_04 via the API so balances are coherent.
        $bank = $this->seedBankWithMaintenancePayments($tenant, $carmenToken, $u, $people);

        // Confirmed income so far: 3 payments = 120000 + 150000 + 120000 = 390000.
        $expectedAfterIncome = 5000000 + 390000;
        $this->assertSame($expectedAfterIncome, $this->bankBalance($tenant, $bank['id']));

        // Provider "Jardines del Valle".
        $provTypeId = $this->inTenant($tenant, fn () => Catalog::id(ProviderType::class, 'Persona moral'));
        $provServiceId = $this->inTenant($tenant, fn () => Catalog::id(ProviderServiceType::class, 'Jardinería'));

        $provider = $this->as($carmenToken, $tenant)->postJson('/api/provider', [
            'name' => 'Jardines del Valle',
            'provider_type_id' => $provTypeId,
            'provider_service_type_id' => $provServiceId,
            'phone' => '5598765432',
            'is_recurring' => true,
            'is_active' => true,
        ])->assertStatus(201)->json('data');

        // Catalog ids for expenses.
        $catSalary = $this->inTenant($tenant, fn () => ExpenseCategory::where('name', 'Salarios')->value('id'));
        $catMaint = $this->inTenant($tenant, fn () => ExpenseCategory::where('name', 'Mantenimiento')->value('id'));
        $benefEmployee = $this->inTenant($tenant, fn () => BeneficiaryType::EMPLOYEE);
        $benefProvider = $this->inTenant($tenant, fn () => BeneficiaryType::PROVIDER);

        // Register the gardener José Luis as an employee — staff management is
        // admin territory (§7.2): the treasurer gets a 403.
        $lauraToken = $this->login($this->scn['emails']['laura']);

        $this->as($carmenToken, $tenant)->postJson('/api/collaborator', [
            'name' => 'José Luis', 'last_name' => 'Hernández',
            'user_id' => $people['joseLuis']->id, 'create_as_user' => false,
        ])->assertStatus(403);

        $joseEmpId = $this->as($lauraToken, $tenant)->postJson('/api/collaborator', [
            'name' => 'José Luis', 'last_name' => 'Hernández',
            'user_id' => $people['joseLuis']->id, 'create_as_user' => false,
        ])->assertStatus(201)->json('data.id');

        $methodTransfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        // Expense 1: gardener salary $8,000 (800000) -> beneficiary employee.
        $salaryExpense = $this->as($carmenToken, $tenant)->postJson('/api/expense', [
            'paid_at' => '2026-06-15',
            'year' => 2026,
            'month' => 6,
            'amount' => 800000,
            'concept' => 'Salario jardinero junio',
            'expense_category_id' => $catSalary,
            'beneficiary_type_id' => $benefEmployee,
            'employee_id' => $joseEmpId,
            'payment_method_id' => $methodTransfer,
            'bank_account_id' => $bank['id'],
        ])->assertStatus(201)->json('data');

        // Expense 2: provider payment $3,500 (350000) -> beneficiary provider.
        $providerExpense = $this->as($carmenToken, $tenant)->postJson('/api/expense', [
            'paid_at' => '2026-06-16',
            'year' => 2026,
            'month' => 6,
            'amount' => 350000,
            'concept' => 'Mantenimiento jardines junio',
            'expense_category_id' => $catMaint,
            'beneficiary_type_id' => $benefProvider,
            'provider_id' => $provider['id'],
            'payment_method_id' => $methodTransfer,
            'bank_account_id' => $bank['id'],
        ])->assertStatus(201)->json('data');

        // Balance = initial + income - both expenses.
        $afterExpenses = $expectedAfterIncome - 800000 - 350000;
        $this->assertSame($afterExpenses, $this->bankBalance($tenant, $bank['id']));

        // Void the provider expense -> balance restored by $3,500.
        // §9.2: the treasurer cannot void; the president can.
        $this->as($carmenToken, $tenant)->postJson("/api/expense/{$providerExpense['id']}/void", [
            'reason' => 'Pago duplicado, se anula.',
        ])->assertStatus(403);

        $robertoToken = $this->login($this->scn['emails']['roberto']);
        $this->as($robertoToken, $tenant)->postJson("/api/expense/{$providerExpense['id']}/void", [
            'reason' => 'Pago duplicado, se anula.',
        ])->assertStatus(200);

        $this->assertSame($afterExpenses + 350000, $this->bankBalance($tenant, $bank['id']));

        // The voided expense keeps its row (never deleted) with voided status.
        $voidedStatusId = $this->inTenant($tenant, fn () => ExpenseStatus::VOIDED);
        $voided = $this->inTenant($tenant, fn () => Expense::find($providerExpense['id']));
        $this->assertSame($voidedStatusId, $voided->expense_status_id);
        $this->assertSame('Pago duplicado, se anula.', $voided->void_reason);

        // Salary expense remains paid.
        $paidStatusId = $this->inTenant($tenant, fn () => ExpenseStatus::PAID);
        $this->assertSame($paidStatusId, $this->inTenant($tenant, fn () => Expense::find($salaryExpense['id'])->expense_status_id));
    }

    // =========================================================================
    // 6. Community project — mandatory $500/unit, activation generates charges
    // =========================================================================

    public function test_06_community_project_mandatory_charges_and_rollup(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        $lauraToken = $this->login($this->scn['emails']['laura']);

        $catId = $this->inTenant($tenant, fn () => Catalog::id(CommunityProjectCategory::class, 'Obra'));
        $mandatoryId = $this->inTenant($tenant, fn () => ParticipationType::MANDATORY);
        $fixedModeId = $this->inTenant($tenant, fn () => AmountMode::FIXED_REQUIRED);
        $draftId = $this->inTenant($tenant, fn () => CommunityProjectStatus::DRAFT);

        $project = $this->as($lauraToken, $tenant)->postJson('/api/community_project', [
            'title' => 'Rehabilitación de la alberca',
            'description' => 'Obra mayor de rehabilitación de la alberca comunitaria',
            'community_project_category_id' => $catId,
            'participation_type_id' => $mandatoryId,
            'amount_mode_id' => $fixedModeId,
            'fixed_amount' => 50000,
            'goal_amount' => 300000,
            'target_type' => 'all',
            'start_date' => '2026-06-01',
            'due_date' => '2026-08-31',
            'visible_to_residents' => true,
            'community_project_status_id' => $draftId,
        ])->assertStatus(201)->json('data');

        // Activate -> one charge + one contribution per unit (6 each).
        $this->as($lauraToken, $tenant)->postJson("/api/community-project/{$project['id']}/activate")->assertStatus(200);

        $projChargeCount = $this->inTenant($tenant, fn () => Charge::where('community_project_id', $project['id'])->count());
        $this->assertSame(6, $projChargeCount, 'Mandatory project generates one charge per unit');

        $contribCount = $this->inTenant($tenant, fn () => ProjectContribution::where('community_project_id', $project['id'])->count());
        $this->assertSame(6, $contribCount);

        foreach ($this->inTenant($tenant, fn () => Charge::where('community_project_id', $project['id'])->get()) as $c) {
            $this->assertSame(50000, (int) $c->getAttributes()['expected_amount']);
        }

        // A resident (Patricia) pays her project contribution. The payment hits the
        // charge; we also record the contribution so the rollup reflects it (the
        // project rollup reads ProjectContribution.contributed_amount).
        $bankTypeId = $this->inTenant($tenant, fn () => BankAccountType::CASH);
        $bankPurposeId = $this->inTenant($tenant, fn () => Catalog::id(BankAccountPurpose::class, 'Caja chica'));
        $bank = $this->as($lauraToken, $tenant)->postJson('/api/bank_account', [
            'name' => 'Caja proyectos',
            'bank' => 'Caja',
            'account_number' => 'CAJA-1',
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_primary' => false,
            'is_active' => true,
            'bank_account_type_id' => $bankTypeId,
            'bank_account_purpose_id' => $bankPurposeId,
        ])->assertStatus(201)->json('data');

        $methodCash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        $patriciaChargeId = $this->inTenant($tenant, fn () => Charge::where('community_project_id', $project['id'])->where('unit_id', $u['depto102']->id)->value('id'));

        // Pay the project charge for Patricia's unit.
        $this->as($lauraToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['depto102']->id,
            'paid_at' => '2026-06-10',
            'total_amount' => 50000,
            'payment_method_id' => $methodCash,
            'bank_account_id' => $bank['id'],
            'manual_applications' => [
                ['charge_id' => $patriciaChargeId, 'apply_amount' => 50000],
            ],
        ])->assertStatus(201);

        // Record the contribution so the project rollup tracks recaudado.
        $this->inTenant($tenant, function () use ($project, $u): void {
            $contribution = ProjectContribution::where('community_project_id', $project['id'])
                ->where('unit_id', $u['depto102']->id)
                ->firstOrFail();
            app(ContributionService::class)->contribute($contribution, 50000);
        });

        // The project charge for Patricia's unit is now completed.
        $completedId = $this->inTenant($tenant, fn () => ChargeStatus::COMPLETED);
        $this->assertSame($completedId, $this->inTenant($tenant, fn () => Charge::find($patriciaChargeId)->charge_status_id));

        // Summary rollup: raised = 50000, executed = 0, available = 50000.
        $summary = $this->as($lauraToken, $tenant)->getJson("/api/community-project/{$project['id']}/summary")->assertStatus(200)->json('data');
        $this->assertSame('50000', $summary['raised']['amount']);
        $this->assertSame('MXN', $summary['raised']['currency']);
        $this->assertSame('0', $summary['executed']['amount']);
        $this->assertSame('50000', $summary['available']['amount']);
    }

    // =========================================================================
    // 7. Announcements — Jorge (secretary) publishes an URGENT notice
    // =========================================================================

    public function test_07_secretary_publishes_urgent_announcement_and_resident_reads(): void
    {
        $tenant = $this->scn['tenant'];

        $jorgeToken = $this->login($this->scn['emails']['jorge']);

        $typeId = $this->inTenant($tenant, fn () => Catalog::id(AnnouncementType::class, 'Mantenimiento'));
        $urgentId = $this->inTenant($tenant, fn () => AnnouncementPriority::URGENT);
        $draftId = $this->inTenant($tenant, fn () => AnnouncementStatus::DRAFT);

        // Create the draft.
        $ann = $this->as($jorgeToken, $tenant)->postJson('/api/announcement', [
            'title' => 'Corte de agua programado el sábado',
            'content' => 'El sábado de 8:00 a 14:00 hrs habrá corte de agua por mantenimiento.',
            'announcement_type_id' => $typeId,
            'announcement_priority_id' => $urgentId,
            'announcement_status_id' => $draftId,
            'target_type' => 'all',
            'channel_push' => false,
            'channel_sms' => false,
        ])->assertStatus(201)->json('data');

        // Publish -> urgent forces push (SMS disabled for now); status becomes published.
        $published = $this->as($jorgeToken, $tenant)->postJson("/api/announcement/{$ann['id']}/publish")->assertStatus(200)->json('data');

        $publishedStatusId = $this->inTenant($tenant, fn () => AnnouncementStatus::PUBLISHED);
        $fresh = $this->inTenant($tenant, fn () => Announcement::find($ann['id']));
        $this->assertSame($publishedStatusId, $fresh->announcement_status_id);
        $this->assertTrue((bool) $fresh->channel_push, 'Urgent must force push channel');
        $this->assertFalse((bool) $fresh->channel_sms, 'SMS is disabled for now — urgent must NOT force SMS channel');
        $this->assertNotNull($fresh->publish_at);

        // Patricia (resident) sees the published announcement on the board and
        // marks it read. The literal GET /api/announcement/board route is now
        // registered BEFORE the Orion resource, so "board" is no longer parsed as
        // a UUID id (the routing bug): it returns 200 with the published board.
        $patriciaToken = $this->login($this->scn['emails']['patricia']);
        $board = $this->as($patriciaToken, $tenant)
            ->getJson('/api/announcement/board')
            ->assertStatus(200)->json('data');
        $boardIds = array_column($board, 'id');
        $this->assertContains($ann['id'], $boardIds, 'Published urgent announcement is visible on the board for residents');

        $this->as($patriciaToken, $tenant)->postJson("/api/announcement/{$ann['id']}/read")->assertStatus(200);

        $readCount = $this->inTenant($tenant, fn () => AnnouncementRead::where('announcement_id', $ann['id'])->count());
        $this->assertSame(1, $readCount, 'A read receipt is recorded for Patricia');
    }

    // =========================================================================
    // 8. Chat — Roberto posts in "General", Patricia (member) reacts
    // =========================================================================

    public function test_08_chat_post_and_react_in_general_channel(): void
    {
        $tenant = $this->scn['tenant'];

        // Two system channels exist per tenant.
        $channels = $this->inTenant($tenant, fn () => ChatChannel::pluck('name')->all());
        $this->assertContains('Avisos oficiales', $channels);
        $this->assertContains('General', $channels);

        $generalId = $this->inTenant($tenant, fn () => ChatChannel::where('name', 'General')->value('id'));

        // Roberto joins General and posts a message.
        $robertoToken = $this->login($this->scn['emails']['roberto']);
        $this->as($robertoToken, $tenant)->postJson("/api/chat-channel/{$generalId}/join")->assertStatus(200);

        $msg = $this->as($robertoToken, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $generalId,
            'user_id' => $this->scn['users']['roberto']->id,
            'body' => 'Buenos días vecinos, recuerden la junta del sábado.',
        ])->assertStatus(201)->json('data');

        // Patricia joins and reacts.
        $patriciaToken = $this->login($this->scn['emails']['patricia']);
        $this->as($patriciaToken, $tenant)->postJson("/api/chat-channel/{$generalId}/join")->assertStatus(200);
        $this->as($patriciaToken, $tenant)->postJson("/api/chat-message/{$msg['id']}/react", ['emoji' => '👍'])->assertStatus(200);

        $reactionCount = $this->inTenant($tenant, fn () => ChatReaction::where('chat_message_id', $msg['id'])->count());
        $this->assertSame(1, $reactionCount, 'Patricia reaction recorded');
    }

    // =========================================================================
    // 9. Access control — Patricia generates QR, Pedro (security) scans it
    // =========================================================================

    public function test_09_access_qr_scan_flow(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];

        $patriciaToken = $this->login($this->scn['emails']['patricia']);

        $motiveId = $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar'));
        $qrTypeId = $this->inTenant($tenant, fn () => QrType::SINGLE_ENTRY);

        // Patricia generates a QR for visitor "Sofía Linares" to her Depto 102.
        $visitor = $this->as($patriciaToken, $tenant)->postJson('/api/visitor', [
            'name' => 'Sofía Linares',
            'unit_id' => $u['depto102']->id,
            'visitor_motive_id' => $motiveId,
            'qr_type_id' => $qrTypeId,
            'arrives_by_vehicle' => false,
        ])->assertStatus(201)->json('data');

        $this->assertNotEmpty($visitor['qr_token']);

        // Pedro (security) scans the QR -> access log created, visitor flips to used.
        $pedroToken = $this->login($this->scn['emails']['pedro']);
        $scan = $this->as($pedroToken, $tenant)->postJson('/api/access/scan-qr', [
            'token' => $visitor['qr_token'],
        ])->assertStatus(200)->json();

        $this->assertTrue($scan['valid']);
        $this->assertNotNull($scan['access_log']);

        $logCount = $this->inTenant($tenant, fn () => AccessLog::where('visitor_id', $visitor['id'])->count());
        $this->assertSame(1, $logCount, 'One access log created on scan');

        $usedStatusId = $this->inTenant($tenant, fn () => VisitorStatus::USED);
        $this->assertSame($usedStatusId, $this->inTenant($tenant, fn () => Visitor::find($visitor['id'])->visitor_status_id));

        // Second scan fails (already used).
        $secondScan = $this->as($pedroToken, $tenant)->postJson('/api/access/scan-qr', [
            'token' => $visitor['qr_token'],
        ])->assertStatus(422)->json();
        $this->assertFalse($secondScan['valid']);

        // Pedro registers a manual entry.
        $verificationId = $this->inTenant($tenant, fn () => AccessVerification::PHONE_CALL);
        $this->as($pedroToken, $tenant)->postJson('/api/access/manual-entry', [
            'unit_id' => $u['casa1']->id,
            'visitor_name' => 'Repartidor Amazon',
            'access_verification_id' => $verificationId,
            'authorized_by' => 'Pedro Vargas',
        ])->assertStatus(201);

        $manualCount = $this->inTenant($tenant, fn () => AccessLog::whereNull('visitor_id')->count());
        $this->assertSame(1, $manualCount, 'Manual entry recorded');

        // access_log is immutable: PUT/DELETE are not registered.
        $someLogId = $this->inTenant($tenant, fn () => AccessLog::value('id'));
        $this->as($pedroToken, $tenant)->putJson("/api/access_log/{$someLogId}", ['visitor_name' => 'x'])->assertStatus(405);
        $this->as($pedroToken, $tenant)->deleteJson("/api/access_log/{$someLogId}")->assertStatus(405);
    }

    // =========================================================================
    // 10. Reports — Carmen (treasurer) pulls financials, incl. a PDF
    // =========================================================================

    public function test_10_treasurer_reports_including_pdf(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];
        $people = $this->scn['users'];

        $carmenToken = $this->login($this->scn['emails']['carmen']);

        // Build a coherent financial state: maintenance batch overdue + payments + an expense.
        $bank = $this->seedBankWithMaintenancePayments($tenant, $carmenToken, $u, $people);

        $catMaint = $this->inTenant($tenant, fn () => ExpenseCategory::where('name', 'Mantenimiento')->value('id'));
        $benefOther = $this->inTenant($tenant, fn () => BeneficiaryType::OTHER);
        $methodCash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);
        $this->as($carmenToken, $tenant)->postJson('/api/expense', [
            'paid_at' => '2026-06-18',
            'year' => 2026,
            'month' => 6,
            'amount' => 100000,
            'concept' => 'Reparación luminarias',
            'expense_category_id' => $catMaint,
            'beneficiary_type_id' => $benefOther,
            'beneficiary_text' => 'Electricista externo',
            'payment_method_id' => $methodCash,
            'bank_account_id' => $bank['id'],
        ])->assertStatus(201);

        // Delinquency report lists overdue units (Casa 3, Depto 101, Depto 201 unpaid).
        $deliq = $this->as($carmenToken, $tenant)->getJson('/api/reports/delinquency')->assertStatus(200)->json();
        $this->assertGreaterThanOrEqual(1, $deliq['unit_count']);
        $this->assertGreaterThan(0, (int) $deliq['total_overdue_amount']['amount']);

        // Income statement: income 390000 (3 payments) - expenses 100000.
        $income = $this->as($carmenToken, $tenant)->getJson('/api/reports/income-statement?period_start=2026-06-01&period_end=2026-06-30')->assertStatus(200)->json();
        $this->assertSame('390000', $income['total_income']['amount']);
        $this->assertSame('MXN', $income['total_income']['currency']);
        $this->assertSame('100000', $income['total_expenses']['amount']);
        $this->assertSame('290000', $income['net']['amount']);

        // Account balances report.
        $balances = $this->as($carmenToken, $tenant)->getJson('/api/reports/account-balances')->assertStatus(200)->json('data');
        $this->assertNotEmpty($balances);

        // PDF generation (sync) of the delinquency report.
        $pdf = $this->as($carmenToken, $tenant)->postJson('/api/reports/delinquency/generate?format=pdf');
        $pdf->assertStatus(200);
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    // =========================================================================
    // 11. Configuration — Laura (admin) reads + updates operational config
    // =========================================================================

    public function test_11_admin_configuration(): void
    {
        $tenant = $this->scn['tenant'];

        $lauraToken = $this->login($this->scn['emails']['laura']);

        // General config is now tenant-isolated: config/general resolves the main
        // company via the tenant-scoped main() scope, so it returns THIS cluster's
        // company (its own legal identity), never another tenant's.
        $general = $this->as($lauraToken, $tenant)->getJson('/api/config/general')
            ->assertStatus(200)->json('data');
        $this->assertSame('Privada Los Encinos A.C.', $general['legal_name'], 'config/general returns THIS cluster company');
        $this->assertSame('PLE260101AB1', $general['rfc']);
        $this->assertSame($tenant->id, $general['tenant_id'], 'config/general is scoped to the current tenant');

        $myCompanyLegal = $this->inTenant($tenant, fn () => Company::where('rfc', 'PLE260101AB1')->value('legal_name'));
        $this->assertSame('Privada Los Encinos A.C.', $myCompanyLegal, "This cluster's main company carries the right legal name");

        // Operational config reflects tenant columns (tenant-scoped, deterministic).
        $op = $this->as($lauraToken, $tenant)->getJson('/api/config/operational')->assertStatus(200)->json('data');
        $this->assertSame('America/Mexico_City', $op['timezone']);
        $this->assertSame('MXN', $op['currency']);

        // Admin updates the operational config (a real write to tenant columns):
        // mark that the cluster does NOT use ownership share for proration.
        $this->as($lauraToken, $tenant)->putJson('/api/config/operational', [
            'uses_ownership_share' => false,
        ])->assertStatus(200);

        $tenant->refresh();
        $this->assertFalse((bool) $tenant->uses_ownership_share, 'Operational config persisted on the tenant');

        // Changing currency/timezone requires explicit confirmation (spec rule):
        // without confirmed=true the change is rejected with 422.
        $this->as($lauraToken, $tenant)->putJson('/api/config/operational', [
            'currency' => 'USD',
        ])->assertStatus(422);

        // Collection settings now PERSIST: the controller reads the literal dotted
        // keys from $request->all() and validates them with escaped-dot rule keys,
        // so the values are actually stored (the dot-notation no-op bug is fixed).
        $put = $this->as($lauraToken, $tenant)->putJson('/api/config/collection-settings', [
            'collections.due_soon_days' => 10,
        ])->assertStatus(200);
        // data_get does not support escaped dots, so read the data array whole.
        $putData = $put->json('data');
        $this->assertSame(10, $putData['collections.due_soon_days']);

        // Read-back returns what was written.
        $readBack = $this->as($lauraToken, $tenant)->getJson('/api/config/collection-settings')
            ->assertStatus(200)->json('data');
        $this->assertSame(10, $readBack['collections.due_soon_days']);

        // The Setting row is persisted tenant-scoped for this cluster.
        $stored = $this->inTenant($tenant, fn () => Setting::getValue('collections.due_soon_days'));
        $this->assertSame(10, $stored, 'due_soon_days persisted to the Setting table');
    }

    // =========================================================================
    // 12. Whole-system coherence — reconcile the whole month end-to-end
    // =========================================================================

    public function test_12_end_to_end_financial_coherence(): void
    {
        $tenant = $this->scn['tenant'];
        $u = $this->scn['units'];
        $people = $this->scn['users'];

        $carmenToken = $this->login($this->scn['emails']['carmen']);
        $bank = $this->seedBankWithMaintenancePayments($tenant, $carmenToken, $u, $people);

        // LEDGER INVARIANT per unit: Σ(charge.paid_amount) + credit == Σ(confirmed payments).
        $this->inTenant($tenant, function () use ($u): void {
            foreach ($u as $unit) {
                $sumPaid = (int) Charge::where('unit_id', $unit->id)->sum('paid_amount');
                $credit = CreditBalance::where('unit_id', $unit->id)->first();
                $creditRaw = $credit ? (int) $credit->getAttributes()['amount'] : 0;
                $confirmedId = PaymentStatus::CONFIRMED;
                $sumConfirmed = (int) Payment::where('unit_id', $unit->id)
                    ->where('payment_status_id', $confirmedId)
                    ->sum('total_amount');

                $this->assertSame(
                    $sumConfirmed,
                    $sumPaid + $creditRaw,
                    "Ledger invariant must hold for unit {$unit->number}"
                );
            }
        });

        // Casa 2 credit is exactly $300 (read raw minor units, bypassing the cast).
        $casa2Credit = $this->inTenant($tenant, function () use ($u): int {
            $cb = CreditBalance::where('unit_id', $u['casa2']->id)->first();

            return $cb ? (int) $cb->getAttributes()['amount'] : 0;
        });
        $this->assertSame(30000, $casa2Credit);

        // Bank balance equals initial + the three confirmed maintenance payments.
        $this->assertSame(5000000 + 390000, $this->bankBalance($tenant, $bank['id']));

        // Income statement net == bank delta from payments (no expenses in this test).
        $income = $this->as($carmenToken, $tenant)->getJson('/api/reports/income-statement?period_start=2026-06-01&period_end=2026-06-30')->assertStatus(200)->json();
        $this->assertSame('390000', $income['total_income']['amount']);
        $this->assertSame('0', $income['total_expenses']['amount']);
    }

    // =========================================================================
    // Shared helpers for the financial sub-state
    // =========================================================================

    /**
     * Re-create the maintenance batch + the three confirmed payments + the bank
     * account, returning the bank account row. Keeps every method's financial
     * state coherent and self-contained.
     *
     * @param  array<string, Unit>  $u
     * @param  array<string, User>  $people
     * @return array<string, mixed>
     */
    private function seedBankWithMaintenancePayments($tenant, string $treasurerToken, array $u, array $people): array
    {
        $catId = $this->inTenant($tenant, fn () => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $modeId = $this->inTenant($tenant, fn () => AmountMode::FIXED_REQUIRED);
        $monthlyId = $this->inTenant($tenant, fn () => Periodicity::MONTHLY);

        $concept = $this->as($treasurerToken, $tenant)->postJson('/api/charge_concept', [
            'code' => 'MANT_MEN',
            'name' => 'Mantenimiento mensual',
            'charge_concept_category_id' => $catId,
            'is_active' => true,
        ])->json('data');

        // The periodic policy creates its DRAFT template (0 charges) PLUS the first-period
        // clone (with charges) on create — no separate batch endpoint call.
        $policy = $this->as($treasurerToken, $tenant)->postJson('/api/charge_policy', [
            'charge_concept_id' => $concept['id'],
            'name' => 'Cuota mensual',
            'amount_mode_id' => $modeId,
            'periodicity_id' => $monthlyId,
            'fixed_amount' => 120000,
            'grace_days' => 5,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'is_active' => true,
            'target_type' => 'all',
            'issued_at' => '2026-05-20',
            'due_date' => '2026-05-25',
        ])->json('data');

        $batch = $this->inTenant($tenant, fn () => ChargeBatch::where('charge_policy_id', $policy['id'])
            ->where('is_initial', false)
            ->firstOrFail()
            ->toArray());

        $bankTypeId = $this->inTenant($tenant, fn () => BankAccountType::BANK);
        $bankPurposeId = $this->inTenant($tenant, fn () => Catalog::id(BankAccountPurpose::class, 'Operativa'));

        // Bank accounts are created by the admin (§9.2) — the treasurer only operates them.
        $adminToken = $this->login($this->scn['emails']['laura']);
        $bank = $this->as($adminToken, $tenant)->postJson('/api/bank_account', [
            'name' => 'Cuenta Operativa BBVA',
            'bank' => 'BBVA',
            'account_number' => '01234567890',
            'currency' => 'MXN',
            'initial_balance' => 5000000,
            'is_primary' => true,
            'is_active' => true,
            'bank_account_type_id' => $bankTypeId,
            'bank_account_purpose_id' => $bankPurposeId,
        ])->json('data');

        $methodCash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);
        $methodTransfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        $this->as($treasurerToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['casa1']->id, 'paid_at' => '2026-06-02', 'total_amount' => 120000,
            'payment_method_id' => $methodCash, 'bank_account_id' => $bank['id'], 'payer_user_id' => $people['roberto']->id,
        ])->assertStatus(201);

        $this->as($treasurerToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['casa2']->id, 'paid_at' => '2026-06-02', 'total_amount' => 150000,
            'payment_method_id' => $methodCash, 'bank_account_id' => $bank['id'], 'payer_user_id' => $people['carmen']->id,
        ])->assertStatus(201);

        $this->as($treasurerToken, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $u['depto102']->id, 'paid_at' => '2026-06-03', 'total_amount' => 120000,
            'payment_method_id' => $methodTransfer, 'bank_account_id' => $bank['id'], 'payer_user_id' => $people['patricia']->id,
            'receipt' => $this->receiptFile(),
        ])->assertStatus(201);

        return $bank;
    }

    private function bankBalance($tenant, string $bankId): int
    {
        return $this->inTenant($tenant, fn () => (int) BankAccount::find($bankId)->getAttributes()['current_balance']);
    }
}
