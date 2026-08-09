<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\ChargeService;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Services\ExpenseService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Batch 3 — Collections/Treasury feature tests.
 *
 *  1. Resident self-capture → PENDING_VALIDATION → validate/reject (spec §8.5.2/§8.5.3/§8.2).
 *  2. Comprobante REQUIRED for non-cash payments (spec §8.7.1 r5).
 *  3. Discount / condonación on a charge (President/Admin only, spec §8.2).
 *  4. Expense file attachments — comprobante + CFDI (spec §9.4.2/§9.4.3).
 *  5. Auto-charge on new unit (spec §6.5 r4).
 *
 * HTTP auth tests use Sanctum token abilities like AuthorizationGuardTest; the global
 * role permissions come from the real ResetPermissionTableSeeder so resident/renter tokens
 * carry create.payment_submission and president carries discount.charge.
 */
final class CollectionsBatch3Test extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
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

    // =========================================================================
    // Helpers — tenant / users / tokens / requests
    // =========================================================================

    /**
     * Seed the global role permissions so real role logins carry real abilities.
     */
    private function seedRolePermissions(): void
    {
        Artisan::call('db:seed', [
            '--class' => ResetPermissionTableSeeder::class,
            '--force' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    /**
     * @param  array<string>  $abilities
     */
    private function tokenWith(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.2,
            ]);
        });

        return $unit;
    }

    private function attachResident(Tenant $tenant, Unit $unit, User $user, bool $billing = true): void
    {
        $tenant->run(function () use ($unit, $user, $billing): void {
            $relationshipId = UnitRelationship::query()->value('id');
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => $relationshipId,
                'is_billing_owner' => $billing,
            ]);
        });
    }

    private function makeBankAccount(Tenant $tenant, int $initialBalanceRaw = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalanceRaw): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalanceRaw,
                'current_balance' => $initialBalanceRaw,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    private function makeEventualFixedPolicy(Tenant $tenant, int $fixedAmountRaw, string $code): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $fixedAmountRaw, $code): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));

            $concept = ChargeConcept::create([
                'code' => $code,
                'name' => 'Cargo de prueba',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política eventual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => $fixedAmountRaw,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
            ]);
        });

        return $policy;
    }

    private function makeMonthlyFixedPolicy(Tenant $tenant, int $fixedAmountRaw, string $code): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $fixedAmountRaw, $code): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            $concept = ChargeConcept::create([
                'code' => $code,
                'name' => 'Mantenimiento mensual',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => $fixedAmountRaw,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'is_active' => true,
                'grace_days' => 0,
            ]);
        });

        return $policy;
    }

    /**
     * Create + activate a single-unit charge for the given unit; returns the Charge id.
     * Must be called INSIDE the tenant context.
     */
    private function activateSingleUnitCharge(ChargePolicy $policy, Unit $unit, string $title, string $due = '2099-12-31'): string
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
        $batch = ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => $title,
            'year' => 2026,
            'month' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $unit->id],
            'issued_at' => '2026-01-01',
            'due_date' => $due,
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
        ]);
        app(ChargeGenerationService::class)->activateBatch($batch);

        return Charge::where('charge_batch_id', $batch->id)->firstOrFail()->id;
    }

    private function fakePdfFile(string $name = 'comprobante.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_b3_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 comprobante ', 200));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    private function fakeXmlFile(string $name = 'cfdi.xml'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_b3_xml_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="x">test</cfdi:Comprobante>');

        return new UploadedFile($tmpPath, $name, 'application/xml', null, true);
    }

    private function assertLedgerInvariant(string $unitId, string $context): void
    {
        $sumPaid = (int) Charge::where('unit_id', $unitId)->sum('paid_amount');

        $credit = CreditBalance::where('unit_id', $unitId)->first();
        $creditRaw = $credit ? (int) $credit->getAttributes()['amount'] : 0;
        $this->assertGreaterThanOrEqual(0, $creditRaw, "credit must not be negative ({$context})");

        $sumConfirmed = (int) Payment::where('unit_id', $unitId)
            ->where('payment_status_id', PaymentStatus::CONFIRMED)
            ->sum('total_amount');

        $this->assertSame(
            $sumConfirmed,
            $sumPaid + $creditRaw,
            "LEDGER INVARIANT ({$context}): Σpaid({$sumPaid}) + credit({$creditRaw}) == Σconfirmed({$sumConfirmed})"
        );
    }

    // =========================================================================
    // FEATURE 1 — Resident self-capture → PENDING_VALIDATION → validate/reject
    // =========================================================================

    public function test_resident_submits_non_cash_without_receipt_returns_422(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant);

        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        $token = $this->tokenWith($resident, ['create.payment_submission']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 150000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, Payment::count(), 'No payment must be created when the comprobante is missing');
        });
    }

    public function test_resident_submits_with_receipt_creates_pending_no_charges_applied_no_bank_change(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant, 0);

        // An open charge exists, but the pending payment must NOT touch it.
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'SUBMIT_C');
        $chargeId = $this->inTenant($tenant, fn () => $this->activateSingleUnitCharge($policy, $unit, 'Submit charge'));

        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);
        $token = $this->tokenWith($resident, ['create.payment_submission']);

        $data = $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'reference' => 'TRX123',
                'receipt' => $this->fakePdfFile(),
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(PaymentStatus::PENDING_VALIDATION, $data['payment_status_id']);
        $this->assertNull($data['receipt_number'], 'No folio is generated until validation');

        $tenant->run(function () use ($chargeId, $bank, $data, $unit): void {
            // Charge untouched.
            $charge = Charge::find($chargeId);
            $this->assertSame(0, (int) $charge->getAttributes()['paid_amount'], 'Pending payment must NOT apply to charges');
            $this->assertSame(0, PaymentApplication::count(), 'No applications for a pending payment');

            // Bank untouched.
            $this->assertSame(0, (int) $bank->refresh()->getAttributes()['current_balance'], 'Pending payment must NOT move bank balance');

            // Receipt media attached.
            $payment = Payment::find($data['id']);
            $this->assertSame(1, $payment->getMedia('receipt')->count(), 'Comprobante must be attached');

            // No credit either; ledger trivially holds (no confirmed payments).
            $this->assertLedgerInvariant($unit->id, 'after resident submit (pending)');
        });
    }

    public function test_resident_submits_cash_without_receipt_is_allowed(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant);

        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);
        $token = $this->tokenWith($resident, ['create.payment_submission']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cash,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(201);
    }

    public function test_resident_cannot_submit_for_a_neighbor_unit(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $ownUnit = $this->makeUnit($tenant);
        $neighborUnit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $ownUnit, $resident);
        $bank = $this->makeBankAccount($tenant);

        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);
        $token = $this->tokenWith($resident, ['create.payment_submission']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $neighborUnit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cash,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(403);
    }

    public function test_tesorero_validates_pending_payment_applies_fifo_increments_bank_generates_folio(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $tesorero = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant, 0);

        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'VALIDATE_C');
        $chargeId = $this->inTenant($tenant, fn () => $this->activateSingleUnitCharge($policy, $unit, 'Validate charge'));

        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        // Resident submits.
        $submitted = $this->tenantRequest($this->tokenWith($resident, ['create.payment_submission']), $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'receipt' => $this->fakePdfFile(),
            ])
            ->assertStatus(201)
            ->json('data');

        // Tesorero validates (admin token simulates the treasurer's update.payment ability).
        $validated = $this->tenantRequest($this->adminToken($tesorero), $tenant)
            ->postJson("/api/payment/{$submitted['id']}/validate")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(PaymentStatus::CONFIRMED, $validated['payment_status_id']);
        $this->assertMatchesRegularExpression('/^R-2026-\d{6}$/', $validated['receipt_number']);

        $tenant->run(function () use ($chargeId, $bank, $unit): void {
            $charge = Charge::find($chargeId);
            $completed = ChargeStatus::COMPLETED;
            $this->assertSame($completed, $charge->charge_status_id, 'Charge must be completed after validation FIFO');
            $this->assertSame(70000, (int) $charge->getAttributes()['paid_amount']);
            $this->assertSame(70000, (int) $bank->refresh()->getAttributes()['current_balance'], 'Bank incremented on validation');
            $this->assertLedgerInvariant($unit->id, 'after validate');
        });
    }

    public function test_tesorero_rejects_pending_payment_stores_reason_no_money_moved(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $tesorero = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant, 0);

        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'REJECT_C');
        $chargeId = $this->inTenant($tenant, fn () => $this->activateSingleUnitCharge($policy, $unit, 'Reject charge'));

        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        $submitted = $this->tenantRequest($this->tokenWith($resident, ['create.payment_submission']), $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'receipt' => $this->fakePdfFile(),
            ])
            ->assertStatus(201)
            ->json('data');

        $rejected = $this->tenantRequest($this->adminToken($tesorero), $tenant)
            ->postJson("/api/payment/{$submitted['id']}/reject", ['reason' => 'Comprobante ilegible, vuelve a subirlo.'])
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(PaymentStatus::CANCELLED, $rejected['payment_status_id']);
        $this->assertSame('Comprobante ilegible, vuelve a subirlo.', $rejected['cancellation_reason']);

        $tenant->run(function () use ($chargeId, $bank, $unit): void {
            $charge = Charge::find($chargeId);
            $this->assertSame(0, (int) $charge->getAttributes()['paid_amount'], 'Reject must not apply to charges');
            $this->assertSame(0, (int) $bank->refresh()->getAttributes()['current_balance'], 'Reject must not move bank balance');
            $this->assertLedgerInvariant($unit->id, 'after reject');
        });
    }

    public function test_resident_cannot_validate_a_payment(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant);

        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        $submitted = $this->tenantRequest($this->tokenWith($resident, ['create.payment_submission']), $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cash,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(201)
            ->json('data');

        // A resident token (no update.payment) cannot validate.
        $this->tenantRequest($this->tokenWith($resident, ['create.payment_submission']), $tenant)
            ->postJson("/api/payment/{$submitted['id']}/validate")
            ->assertStatus(403);
    }

    // =========================================================================
    // FEATURE 2 — Comprobante required for non-cash on the TESORERO register path
    // =========================================================================

    public function test_tesorero_register_transfer_without_receipt_returns_422(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $tesorero = $this->makeUser($tenant);
        $bank = $this->makeBankAccount($tenant);

        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        $this->tenantRequest($this->adminToken($tesorero), $tenant)
            ->postJson('/api/payment/register', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(422);
    }

    public function test_tesorero_register_transfer_with_receipt_succeeds(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $tesorero = $this->makeUser($tenant);
        $bank = $this->makeBankAccount($tenant);

        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);

        $data = $this->tenantRequest($this->adminToken($tesorero), $tenant)
            ->postJson('/api/payment/register', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'receipt' => $this->fakePdfFile(),
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(PaymentStatus::CONFIRMED, $data['payment_status_id']);

        $tenant->run(function () use ($data): void {
            $payment = Payment::find($data['id']);
            $this->assertSame(1, $payment->getMedia('receipt')->count());
        });
    }

    public function test_tesorero_register_cash_without_receipt_succeeds(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $tesorero = $this->makeUser($tenant);
        $bank = $this->makeBankAccount($tenant);

        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        $this->tenantRequest($this->adminToken($tesorero), $tenant)
            ->postJson('/api/payment/register', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cash,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // FEATURE 3 — Discount / condonación (President/Admin only)
    // =========================================================================

    public function test_president_condones_partial_then_payment_completes_charge(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'DISC_PARTIAL');

        $tenant->run(function () use ($policy, $unit, $bank): void {
            $chargeId = $this->activateSingleUnitCharge($policy, $unit, 'Discount partial');
            $charge = Charge::find($chargeId);

            // President condones $200 (20000 minor units) of $700.
            $service = app(ChargeService::class);
            $service->applyDiscount($charge, 20000, 'Acuerdo de asamblea', null);

            $charge->refresh();
            $this->assertSame(20000, (int) $charge->getAttributes()['discount_amount']);

            // Effective expected is now $500.
            $effective = app(ChargeStatusService::class)->effectiveExpected($charge);
            $this->assertSame(50000, $effective, 'effective_expected = 70000 - 20000');

            // Pay $500 → charge completed.
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-15',
                'total_amount' => 50000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            $charge->refresh();
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id, 'Paying the effective amount completes the charge');
            $this->assertSame(50000, (int) $charge->getAttributes()['paid_amount']);

            // No credit created (discount is not a payment).
            $credit = CreditBalance::where('unit_id', $unit->id)->first();
            $this->assertTrue($credit === null || (int) $credit->getAttributes()['amount'] === 0, 'Discount must NOT create credit');

            // Ledger invariant: Σpaid + credit == Σconfirmed (discount reduces expected, not paid).
            $this->assertLedgerInvariant($unit->id, 'after partial discount + payment');
        });
    }

    public function test_president_full_condonation_completes_charge_with_zero_paid(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'DISC_FULL');

        $tenant->run(function () use ($policy, $unit): void {
            $chargeId = $this->activateSingleUnitCharge($policy, $unit, 'Discount full');
            $charge = Charge::find($chargeId);

            app(ChargeService::class)->applyDiscount($charge, 70000, 'Condonación total por error de cobro', null);

            $charge->refresh();
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id, 'Full condonación → completed');
            $this->assertSame(0, (int) $charge->getAttributes()['paid_amount'], 'Completed with 0 paid');

            $credit = CreditBalance::where('unit_id', $unit->id)->first();
            $this->assertTrue($credit === null || (int) $credit->getAttributes()['amount'] === 0);
            $this->assertLedgerInvariant($unit->id, 'after full condonación');
        });
    }

    public function test_discount_is_reflected_in_statement_and_delinquency_outstanding(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'DISC_STMT');

        $chargeId = $this->inTenant($tenant, function () use ($policy, $unit) {
            $id = $this->activateSingleUnitCharge($policy, $unit, 'Discount statement', '2020-01-01'); // overdue
            app(ChargeService::class)->applyDiscount(Charge::find($id), 30000, 'Descuento parcial', null);

            return $id;
        });

        // Statement outstanding must use the EFFECTIVE expected (70000 - 30000 = 40000).
        $statement = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/unit/{$unit->id}/statement")
            ->assertStatus(200)
            ->json();

        $this->assertSame('40000', $statement['totals']['outstanding']['amount'], 'Statement outstanding uses effective expected');
        $this->assertSame('MXN', $statement['totals']['outstanding']['currency']);

        // Delinquency outstanding must also use the effective expected.
        $delinquency = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson('/api/delinquency')
            ->assertStatus(200)
            ->json();

        $this->assertSame('40000', $delinquency['total_outstanding']['amount'], 'Delinquency outstanding uses effective expected');
        $this->assertSame('MXN', $delinquency['total_outstanding']['currency']);

        // Each delinquency row carries a human-readable unit_full_identifier
        // (a "Casa" without floor → "Casa {number}").
        $row = $delinquency['data'][0];
        $this->assertArrayHasKey('unit_full_identifier', $row);
        $this->assertSame('Casa '.$unit->number, $row['unit_full_identifier']);
    }

    public function test_president_can_discount_over_http_but_tesorero_cannot(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $tesorero = $this->makeUser($tenant);
        $president = $this->makeUser($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'DISC_HTTP');

        $chargeId = $this->inTenant($tenant, fn () => $this->activateSingleUnitCharge($policy, $unit, 'Discount http'));

        // Treasurer carries broad update.charge but NOT discount.charge → 403.
        $this->tenantRequest($this->tokenWith($tesorero, ['update.charge']), $tenant)
            ->postJson("/api/charge/{$chargeId}/discount", ['amount' => 10000, 'reason' => 'Intento tesorero'])
            ->assertStatus(403);

        // President carries discount.charge → 200.
        $this->tenantRequest($this->tokenWith($president, ['discount.charge']), $tenant)
            ->postJson("/api/charge/{$chargeId}/discount", ['amount' => 10000, 'reason' => 'Condonación autorizada'])
            ->assertStatus(200);

        $tenant->run(function () use ($chargeId): void {
            $this->assertSame(10000, (int) Charge::find($chargeId)->getAttributes()['discount_amount']);
        });
    }

    public function test_discount_cannot_exceed_outstanding(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'DISC_OVER');

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($policy, $unit, $bank): void {
            $chargeId = $this->activateSingleUnitCharge($policy, $unit, 'Discount over');

            // Pay 30000 first.
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-15',
                'total_amount' => 30000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            // Outstanding is 40000; condoning 50000 must throw.
            app(ChargeService::class)->applyDiscount(Charge::find($chargeId), 50000, 'Demasiado', null);
        });
    }

    // =========================================================================
    // FEATURE 4 — Expense file attachments (comprobante + CFDI)
    // =========================================================================

    public function test_expense_attaches_receipt_and_cfdi_tenant_scoped(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);

        $expenseId = $this->inTenant($tenant, function () use ($account) {
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::TRANSFER);

            $result = app(ExpenseService::class)->register([
                'concept' => 'Reparación bomba',
                'paid_at' => now()->toDateString(),
                'amount' => 120000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'year' => (int) now()->year,
                'month' => (int) now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $account->id,
            ]);

            return $result['expense']->id;
        });

        // Attach a receipt.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/expense/{$expenseId}/media", [
                'collection' => 'receipt',
                'file' => $this->fakePdfFile('comprobante-gasto.pdf'),
            ])
            ->assertStatus(201);

        // Attach a CFDI (xml).
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/expense/{$expenseId}/media", [
                'collection' => 'cfdi',
                'file' => $this->fakeXmlFile('factura.xml'),
            ])
            ->assertStatus(201);

        // Attach a CFDI (pdf).
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/expense/{$expenseId}/media", [
                'collection' => 'cfdi',
                'file' => $this->fakePdfFile('factura.pdf'),
            ])
            ->assertStatus(201);

        $tenant->run(function () use ($expenseId): void {
            $expense = Expense::find($expenseId);
            $this->assertSame(1, $expense->getMedia('receipt')->count(), 'Expense must have 1 receipt');
            $this->assertSame(2, $expense->getMedia('cfdi')->count(), 'Expense CFDI collection holds XML + PDF');
            // Media is tenant-scoped via the owning expense (which carries tenant_id).
            $this->assertSame($expenseId, $expense->getMedia('receipt')->first()->model_id);
        });
    }

    // =========================================================================
    // FEATURE 5 — Auto-charge on new unit (spec §6.5 r4)
    // =========================================================================

    public function test_new_unit_mid_period_auto_receives_active_batch_charge_idempotently(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Active "Mantenimiento" batch (target all) for the current period.
        $now = now();
        $policy = $this->makeMonthlyFixedPolicy($tenant, 120000, 'AUTO_MANT'); // $1,200

        $tenant->run(function () use ($policy, $now): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            // Pre-existing unit so the batch has at least one target at activation.
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $active = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => 'Existing-'.mt_rand(1, 9999),
                'unit_status_id' => $active->id,
                'ownership_share' => 1.0,
            ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Mantenimiento Junio',
                'year' => $now->year,
                'month' => $now->month,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => $now->toDateString(),
                'due_date' => $now->copy()->addDays(15)->toDateString(),
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            app(ChargeGenerationService::class)->activateBatch($batch);

            // Now a NEW unit is added mid-month → it must auto-receive the $1,200 charge.
            $newUnit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => 'New-'.mt_rand(1, 9999),
                'unit_status_id' => $active->id,
                'ownership_share' => 1.0,
            ]);

            $charges = Charge::where('charge_batch_id', $batch->id)->where('unit_id', $newUnit->id)->get();
            $this->assertCount(1, $charges, 'New unit must auto-receive the active batch charge');
            $this->assertSame(120000, (int) $charges->first()->getAttributes()['expected_amount']);

            // Idempotent: re-running generation must NOT duplicate.
            app(ChargeGenerationService::class)->generateForNewUnit($newUnit->refresh());
            $this->assertSame(
                1,
                Charge::where('charge_batch_id', $batch->id)->where('unit_id', $newUnit->id)->count(),
                'Re-running generation must not double-charge'
            );
        });
    }

    public function test_new_inactive_unit_does_not_auto_receive_a_charge(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $now = now();
        $policy = $this->makeMonthlyFixedPolicy($tenant, 120000, 'AUTO_INACTIVE');

        $tenant->run(function () use ($policy, $now): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $active = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $inactive = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Inactiva'));

            Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => 'Existing-'.mt_rand(1, 9999),
                'unit_status_id' => $active->id,
                'ownership_share' => 1.0,
            ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Mantenimiento Junio',
                'year' => $now->year,
                'month' => $now->month,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => $now->toDateString(),
                'due_date' => $now->copy()->addDays(15)->toDateString(),
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);
            app(ChargeGenerationService::class)->activateBatch($batch);

            $newInactive = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => 'New-Inactive-'.mt_rand(1, 9999),
                'unit_status_id' => $inactive->id,
                'ownership_share' => 1.0,
            ]);

            $this->assertSame(
                0,
                Charge::where('charge_batch_id', $batch->id)->where('unit_id', $newInactive->id)->count(),
                'An inactive new unit must NOT receive a charge (§6.5 r3)'
            );
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
}
