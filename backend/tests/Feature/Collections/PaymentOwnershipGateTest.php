<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
 * Membership gates must reject EX-residents (unit_user rows whose link was
 * closed with end_date). A former resident keeps a historical unit_user row;
 * without whereNull('end_date') they would still pass the gate and (a) submit
 * payments and (b) read the current resident's account statement.
 */
final class PaymentOwnershipGateTest extends TestCase
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

    public function test_active_resident_can_read_own_statement_but_ex_resident_cannot(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // Active resident → 200.
        $active = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $active, endDate: null);
        $activeToken = $this->tokenWith($active, ['read.payment_application']);
        $this->tenantRequest($activeToken, $tenant)
            ->getJson("/api/unit/{$unit->id}/statement")
            ->assertStatus(200);

        // Ex-resident (link closed in the past) → 403.
        $ex = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $ex, endDate: '2025-01-01');
        $exToken = $this->tokenWith($ex, ['read.payment_application']);
        $this->tenantRequest($exToken, $tenant)
            ->getJson("/api/unit/{$unit->id}/statement")
            ->assertStatus(403);
    }

    public function test_ex_resident_cannot_submit_payment_for_old_unit(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant);
        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        // Ex-resident: historical link with a past end_date.
        $ex = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $ex, endDate: '2025-01-01');
        $exToken = $this->tokenWith($ex, ['create.payment_submission']);

        $this->tenantRequest($exToken, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $cash,
                'bank_account_id' => $bank->id,
            ])
            ->assertStatus(403);
    }

    public function test_active_resident_passes_the_submission_gate(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant);
        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        $active = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $active, endDate: null);
        $token = $this->tokenWith($active, ['create.payment_submission']);

        // Cash submission needs no receipt; an active resident must NOT be 403.
        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $cash,
                'bank_account_id' => $bank->id,
                'receipt' => $this->fakePdfFile(),
            ]);

        $this->assertNotSame(403, $response->status(), 'An active resident must pass the membership gate');
    }

    /**
     * §8.5.2: the resident does NOT capture the destination account. A submit WITHOUT
     * bank_account_id must succeed and land on the cluster's primary active account, in
     * PENDING_VALIDATION. (The admin/register path keeps bank_account_id required.)
     */
    public function test_resident_can_submit_without_bank_account_and_defaults_to_primary(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        // makeBankAccount creates the primary active account for the cluster.
        $primary = $this->makeBankAccount($tenant);
        $cash = $this->inTenant($tenant, fn () => PaymentMethod::CASH);

        $active = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $active, endDate: null);
        $token = $this->tokenWith($active, ['create.payment_submission']);

        // Cash submit, NO bank_account_id in the payload.
        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $cash,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.bank_account_id', $primary->id);
        $response->assertJsonPath('data.payment_status_id', PaymentStatus::PENDING_VALIDATION);
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

    private function fakePdfFile(string $name = 'comprobante.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_gate_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 comprobante ', 200));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }
}
