<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\DirectChargeService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Direct counter charge (cobro directo / cargo puntual) — Phase 1 (staff at the counter).
 *
 * The backbone assertion (test #1) is anti-FIFO: a direct charge is born paid and PINNED
 * to its own new charge (manual application), so a unit's old maintenance debt is NEVER
 * touched by the counter payment. Also covers the endpoint contract, comprobante rules,
 * disabled-method gate, permission gate and transactional atomicity.
 */
final class DirectChargeTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // #1 CORE: payment is pinned to the NEW charge — old debt is never touched
    // =========================================================================

    public function test_direct_charge_pins_payment_and_never_touches_old_debt(): void
    {
        $tenant = $this->makeTenant('DirectCharge Core');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bank): void {
            // An OLD, overdue, unpaid maintenance charge — exactly what FIFO would grab.
            $oldCharge = $this->makeOldMaintenanceCharge($unit, 150000);
            $this->assertSame(ChargeStatus::OVERDUE, $oldCharge->charge_status_id);

            $accessCardId = $this->makeDomainConcept()->id;

            $result = app(DirectChargeService::class)->create([
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
                'paid_at' => '2026-07-03',
            ]);

            $newCharge = $result['charge'];
            $payment = $result['payment'];

            // The new charge is fully paid and completed, ad-hoc (no batch, no policy).
            $this->assertNull($newCharge->charge_batch_id);
            $this->assertNull($newCharge->charge_policy_id);
            $this->assertSame(20000, (int) $newCharge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $newCharge->charge_status_id);
            $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', (string) $payment->receipt_number);

            // The single application landed on the NEW charge only.
            $apps = PaymentApplication::where('payment_id', $payment->id)->get();
            $this->assertCount(1, $apps);
            $this->assertSame($newCharge->id, $apps->first()->charge_id);
            $this->assertSame(20000, (int) $apps->first()->getAttributes()['applied_amount']);

            // The OLD debt is untouched: no application, paid still 0, still overdue.
            $this->assertSame(0, PaymentApplication::where('charge_id', $oldCharge->id)->count());
            $oldCharge->refresh();
            $this->assertSame(0, (int) $oldCharge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::OVERDUE, $oldCharge->charge_status_id);

            // The bank received exactly the counter amount.
            $this->assertSame(20000, (int) $bank->refresh()->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // #2 "Otro": free-text motive uses the OTHER concept + stores charge.description
    // =========================================================================

    public function test_free_text_uses_other_concept_and_stores_description(): void
    {
        $tenant = $this->makeTenant('DirectCharge Otro');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bank): void {
            $otherId = ChargeConcept::where('code', 'OTHER')->firstOrFail()->id;

            $result = app(DirectChargeService::class)->create([
                'unit_id' => $unit->id,
                'concept_free_text' => 'Renta de mobiliario adicional',
                'expected_amount' => 35000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
            ]);

            $charge = $result['charge'];
            $this->assertSame($otherId, $charge->charge_concept_id);
            $this->assertSame('Renta de mobiliario adicional', $charge->description);
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);
        });
    }

    // =========================================================================
    // #3 Atomicity: a failing payment rolls back the ad-hoc charge (no orphan)
    // =========================================================================

    public function test_atomicity_rolls_back_charge_when_payment_fails(): void
    {
        $tenant = $this->makeTenant('DirectCharge Atomic');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $accessCardId = $this->makeDomainConcept()->id;

            $threw = false;
            try {
                // A non-existent bank account → Payment::create violates the FK → the whole
                // transaction (charge + payment) rolls back.
                app(DirectChargeService::class)->create([
                    'unit_id' => $unit->id,
                    'charge_concept_id' => $accessCardId,
                    'expected_amount' => 20000,
                    'payment_method_id' => PaymentMethod::CASH,
                    'bank_account_id' => '00000000-0000-0000-0000-000000000000',
                ]);
            } catch (\Throwable $e) {
                $threw = true;
            }

            $this->assertTrue($threw, 'A failing payment must throw and abort the operation.');
            $this->assertSame(0, Charge::where('unit_id', $unit->id)->count(), 'The ad-hoc charge must not persist when the payment fails.');
        });
    }

    // =========================================================================
    // #4 Endpoint happy path (CASH, no receipt) → 201 + response contract
    // =========================================================================

    public function test_endpoint_creates_charge_and_confirmed_payment_with_cash(): void
    {
        $tenant = $this->makeTenant('DirectCharge HTTP Cash');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $token = $this->tokenWith($this->makeUser($tenant), ['*']);
        $accessCardId = $this->inTenant($tenant, fn () => $this->makeDomainConcept()->id);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge/direct', [
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
                'paid_at' => '2026-07-03',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.charge.charge_status_id', ChargeStatus::COMPLETED)
            ->assertJsonPath('data.charge.charge_batch_id', null);

        $this->assertNotNull($response->json('data.charge.id'));
        $this->assertNotNull($response->json('data.payment.id'));
        $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', (string) $response->json('data.payment.receipt_number'));

        $this->assertSame(20000, (int) $this->inTenant($tenant, fn () => $bank->refresh()->getAttributes()['current_balance']));
    }

    // =========================================================================
    // #5 Comprobante REQUIRED for non-cash methods (422 without file)
    // =========================================================================

    public function test_transfer_requires_receipt(): void
    {
        $tenant = $this->makeTenant('DirectCharge Transfer NoReceipt');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $token = $this->tokenWith($this->makeUser($tenant), ['*']);
        $accessCardId = $this->inTenant($tenant, fn () => $this->makeDomainConcept()->id);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge/direct', [
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $bank->id,
                'reference' => 'TRX-1',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('comprobante', (string) $response->json('message'));
    }

    // =========================================================================
    // #6 Comprobante attached for a transfer → 201 (cash allowed without one)
    // =========================================================================

    public function test_transfer_with_receipt_succeeds(): void
    {
        $tenant = $this->makeTenant('DirectCharge Transfer WithReceipt');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $token = $this->tokenWith($this->makeUser($tenant), ['*']);
        $accessCardId = $this->inTenant($tenant, fn () => $this->makeDomainConcept()->id);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge/direct', [
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $bank->id,
                'reference' => 'TRX-2',
                'receipt' => $this->fakeFile('comprobante.pdf', 'application/pdf'),
            ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.payment.receipt_number'));
    }

    // =========================================================================
    // #7 A payment method disabled in cluster config is rejected (422)
    // =========================================================================

    public function test_disabled_payment_method_is_rejected(): void
    {
        $tenant = $this->makeTenant('DirectCharge Disabled');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        // Only CASH is enabled → TRANSFER is disabled.
        $this->inTenant($tenant, fn () => Setting::create([
            'name' => 'Métodos de pago habilitados',
            'slug' => 'collections.payment_methods_enabled',
            'value' => [PaymentMethod::CASH],
            'type' => Setting::TYPE_JSON,
        ]));

        $token = $this->tokenWith($this->makeUser($tenant), ['*']);
        $accessCardId = $this->inTenant($tenant, fn () => $this->makeDomainConcept()->id);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge/direct', [
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $bank->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('habilitado', (string) $response->json('message'));
    }

    // =========================================================================
    // #8 Permission gate: create.payment WITHOUT create.charge → 403
    // =========================================================================

    public function test_user_without_create_charge_is_forbidden(): void
    {
        $tenant = $this->makeTenant('DirectCharge Forbidden');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        // Holds create.payment but NOT create.charge → the endpoint requires BOTH.
        $token = $this->tokenWith($this->makeUser($tenant), ['create.payment']);
        $accessCardId = $this->inTenant($tenant, fn () => $this->makeDomainConcept()->id);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge/direct', [
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(403);
    }

    // =========================================================================
    // #9 Mutually exclusive: sending BOTH a catalog concept AND a free-text motive -> 422
    // =========================================================================

    public function test_both_concept_id_and_free_text_is_rejected(): void
    {
        $tenant = $this->makeTenant('DirectCharge BothConcepts');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $token = $this->tokenWith($this->makeUser($tenant), ['*']);
        $accessCardId = $this->inTenant($tenant, fn () => $this->makeDomainConcept()->id);

        // charge_concept_id AND concept_free_text are mutually exclusive: the free-text
        // path silently wins in the service, so the API rejects the ambiguous combo (422)
        // instead of ignoring the catalog concept the caller also sent.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge/direct', [
                'unit_id' => $unit->id,
                'charge_concept_id' => $accessCardId,
                'concept_free_text' => 'Motivo libre',
                'expected_amount' => 20000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('concept_free_text');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A DOMAIN concept as a source module would create it (they are no longer pre-seeded).
     * Lives in the seeded, protected "Servicios / Eventuales" category. Must be called
     * inside the tenant context. Unique code avoids the (tenant, code) unique index.
     */
    private function makeDomainConcept(): ChargeConcept
    {
        $categoryId = ChargeConceptCategory::where('key', ChargeConceptCategory::KEY_SERVICES)->value('id');

        return ChargeConcept::create([
            'code' => 'ACCESS_CARD_'.mt_rand(1000, 99999),
            'name' => 'Reposición de tarjeta de acceso',
            'charge_concept_category_id' => $categoryId,
            'is_active' => true,
        ]);
    }

    private function makeOldMaintenanceCharge(Unit $unit, int $amount): Charge
    {
        $categoryId = Catalog::id(ChargeConceptCategory::class, 'Ordinaria');
        $concept = ChargeConcept::create([
            'code' => 'MAINT_OLD_'.mt_rand(1000, 99999),
            'name' => 'Mantenimiento',
            'charge_concept_category_id' => $categoryId,
            'is_active' => true,
        ]);

        $charge = new Charge;
        $charge->fill([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $amount,
            'issued_at' => '2025-01-01',
            'due_date' => '2025-01-31',
            'grace_days' => 0,
        ]);
        $charge->paid_amount = 0;
        $charge->is_cancelled = false;
        $charge->charge_status_id = app(ChargeStatusService::class)->resolveStatusKey($charge);
        $charge->save();

        return $charge;
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
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function makeBankAccount(Tenant $tenant, int $initialBalance = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalance): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalance,
                'current_balance' => $initialBalance,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function tokenWith(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function fakeFile(string $name, string $mime): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'direct_up_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 comprobante ', 200));

        return new UploadedFile($tmpPath, $name, $mime, null, true);
    }
}
