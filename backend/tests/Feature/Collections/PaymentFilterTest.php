<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for PaymentController filter extensions (payments-filters).
 *
 * Mirrors ChargeFilterTest. Covers the Orion filters the Pagos filter panel sends:
 *  - payment_status_id (in operator)
 *  - payment_method_id (in operator)
 *  - total_amount (>= / <= range, minor units)
 *  - paid_at (>= / <= range)
 *
 * Data setup uses $tenant->run() so BelongsToTenant stamps tenant_id correctly.
 * HTTP calls use tenantRequest() so the API sees the same tenant scope.
 */
final class PaymentFilterTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    private string $token;

    private string $unitTypeId;

    private string $unitStatusId;

    private string $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('Payment Filter Test Cluster');
        $this->seedTenant($this->tenant);

        $this->tenant->run(function (): void {
            $this->unitTypeId = Catalog::id(UnitType::class, 'Casa', $this->tenant);
            $this->unitStatusId = Catalog::id(UnitStatus::class, 'Activa', $this->tenant);

            $bankAccount = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa', $this->tenant),
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
            $this->bankAccountId = $bankAccount->id;
        });

        $user = $this->makeUser($this->tenant);
        $this->token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($this->token, $this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createUnit(): Unit
    {
        $unit = null;
        $this->tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'number' => (string) mt_rand(100, 99999),
                'unit_type_id' => $this->unitTypeId,
                'unit_status_id' => $this->unitStatusId,
            ]);
        });

        /** @var Unit $unit */
        return $unit;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPayment(Unit $unit, array $overrides = []): Payment
    {
        $payment = null;
        $this->tenant->run(function () use ($unit, $overrides, &$payment): void {
            $payment = Payment::create(array_merge([
                'unit_id' => $unit->id,
                'bank_account_id' => $this->bankAccountId,
                'paid_at' => now()->toDateString(),
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'payment_status_id' => PaymentStatus::CONFIRMED,
            ], $overrides));
        });

        /** @var Payment $payment */
        return $payment;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function searchPayments(array $body = []): TestResponse
    {
        return $this->postJson('/api/payment/search', $body);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    public function test_filters_payments_by_payment_status_id_in(): void
    {
        $unit = $this->createUnit();

        $confirmed = $this->createPayment($unit, ['payment_status_id' => PaymentStatus::CONFIRMED]);
        $pending = $this->createPayment($unit, ['payment_status_id' => PaymentStatus::PENDING_VALIDATION]);
        $cancelled = $this->createPayment($unit, ['payment_status_id' => PaymentStatus::CANCELLED]);

        $response = $this->searchPayments([
            'filters' => [
                ['field' => 'payment_status_id', 'operator' => 'in', 'value' => [PaymentStatus::CONFIRMED, PaymentStatus::PENDING_VALIDATION]],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($confirmed->id, $ids);
        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_filters_payments_by_payment_method_id_in(): void
    {
        $unit = $this->createUnit();

        $cash = $this->createPayment($unit, ['payment_method_id' => PaymentMethod::CASH]);
        $transfer = $this->createPayment($unit, ['payment_method_id' => PaymentMethod::TRANSFER]);
        $check = $this->createPayment($unit, ['payment_method_id' => PaymentMethod::CHECK]);

        $response = $this->searchPayments([
            'filters' => [
                ['field' => 'payment_method_id', 'operator' => 'in', 'value' => [PaymentMethod::TRANSFER]],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($transfer->id, $ids);
        $this->assertNotContains($cash->id, $ids);
        $this->assertNotContains($check->id, $ids);
    }

    public function test_filters_payments_by_total_amount_between(): void
    {
        $unit = $this->createUnit();

        $low = $this->createPayment($unit, ['total_amount' => 5000]);    // $50
        $mid = $this->createPayment($unit, ['total_amount' => 15000]);   // $150
        $high = $this->createPayment($unit, ['total_amount' => 50000]);  // $500

        $response = $this->searchPayments([
            'filters' => [
                ['field' => 'total_amount', 'operator' => '>=', 'value' => 10000],
                ['field' => 'total_amount', 'operator' => '<=', 'value' => 20000],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mid->id, $ids);
        $this->assertNotContains($low->id, $ids);
        $this->assertNotContains($high->id, $ids);
    }

    public function test_filters_payments_by_paid_at_between(): void
    {
        $unit = $this->createUnit();

        $past = $this->createPayment($unit, ['paid_at' => '2024-01-15']);
        $current = $this->createPayment($unit, ['paid_at' => '2025-06-01']);
        $future = $this->createPayment($unit, ['paid_at' => '2026-12-31']);

        $response = $this->searchPayments([
            'filters' => [
                ['field' => 'paid_at', 'operator' => '>=', 'value' => '2025-01-01'],
                ['field' => 'paid_at', 'operator' => '<=', 'value' => '2025-12-31'],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($current->id, $ids);
        $this->assertNotContains($past->id, $ids);
        $this->assertNotContains($future->id, $ids);
    }
}
