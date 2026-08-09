<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * GET /payment/{id} (Orion show) is the destination of the collection deep-links
 * (payment_accepted / payment_registered_direct), which target the RESIDENT.
 *
 * The default Orion show ability is the broad read.payment, which residents do
 * NOT carry. PaymentController::authorize() relaxes the SINGLE-record show so a
 * resident may read a payment belonging to one of THEIR active units (unit_user
 * pivot, mirroring statement()/submit()), while index/search stay broad-only and
 * cross-unit / cross-tenant access is rejected.
 */
final class PaymentShowAccessTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_active_resident_can_show_own_payment(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $payment = $this->makePayment($tenant, $unit);

        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident, endDate: null);
        // Residents carry read.payment_application (the statement gate), NOT read.payment.
        $token = $this->tokenWith($resident, ['read.payment_application']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/payment/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $payment->id);
    }

    public function test_resident_cannot_show_payment_of_another_unit_same_tenant(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $ownUnit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);
        $otherPayment = $this->makePayment($tenant, $otherUnit);

        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $ownUnit, $resident, endDate: null);
        $token = $this->tokenWith($resident, ['read.payment_application']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/payment/{$otherPayment->id}")
            ->assertStatus(403);
    }

    public function test_ex_resident_cannot_show_payment_of_former_unit(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $payment = $this->makePayment($tenant, $unit);

        $ex = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $ex, endDate: '2025-01-01');
        $token = $this->tokenWith($ex, ['read.payment_application']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/payment/{$payment->id}")
            ->assertStatus(403);
    }

    public function test_board_with_read_payment_can_show_any_payment(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $payment = $this->makePayment($tenant, $unit);

        // A board/admin user who is NOT a member of the unit but holds read.payment.
        $board = $this->makeUser($tenant);
        $token = $this->tokenWith($board, ['read.payment']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/payment/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $payment->id);
    }

    public function test_resident_cannot_show_payment_of_another_tenant(): void
    {
        $this->seedRolePermissions();
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Payment lives in tenant B, on a unit of tenant B.
        $unitB = $this->makeUnit($tenantB);
        $paymentB = $this->makePayment($tenantB, $unitB);

        // Resident belongs to tenant A only.
        $unitA = $this->makeUnit($tenantA);
        $resident = $this->makeUser($tenantA);
        $this->attachResident($tenantA, $unitA, $resident, endDate: null);
        $token = $this->tokenWith($resident, ['read.payment_application']);

        // Even with tenant A scope, tenant B's payment is not resolvable → 404.
        $this->tenantRequest($token, $tenantA)
            ->getJson("/api/payment/{$paymentB->id}")
            ->assertStatus(404);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    private function makePayment(Tenant $tenant, Unit $unit): Payment
    {
        $payment = null;
        $tenant->run(function () use ($unit, &$payment): void {
            $bank = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);

            $payment = Payment::create([
                'unit_id' => $unit->id,
                'bank_account_id' => $bank->id,
                'paid_at' => now()->toDateString(),
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'payment_status_id' => PaymentStatus::CONFIRMED,
            ]);
        });

        return $payment;
    }

    private function attachResident(Tenant $tenant, Unit $unit, User $user, ?string $endDate): void
    {
        $tenant->run(function () use ($unit, $user, $endDate): void {
            $relationshipId = UnitRelationship::query()->value('id');
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => $relationshipId,
                'is_billing_owner' => $endDate === null,
                'start_date' => '2024-01-01',
                'end_date' => $endDate !== null ? CarbonImmutable::parse($endDate) : null,
            ]);
        });
    }
}
