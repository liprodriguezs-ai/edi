<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S4 — AmenityCreateRequest cross-field validation:
 *  - `has_cost=true` requires `cost_amount` AND `bank_account_id`.
 *  - `bank_account_id` must belong to the requesting tenant (Rule::exists
 *    ignores TenantScope, so this has to be checked explicitly — mirrors
 *    ExpenseCreateRequest's scoped exists rule).
 */
final class AmenityCreateValidationTest extends TestCase
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

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function adminTenant(string $name = 'Cluster'): array
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        return [$tenant, $token];
    }

    private function basePayload(): array
    {
        return [
            'name' => 'Salón de eventos '.uniqid(),
            'capacity' => 40,
            'available_weekdays' => [1, 2, 3, 4, 5],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ];
    }

    public function test_has_cost_without_bank_account_id_returns_422(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', array_merge($this->basePayload(), [
                'has_cost' => true,
                'cost_amount' => 15000,
                // bank_account_id intentionally omitted
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_account_id');
    }

    private function makeBankAccountId(Tenant $tenant): string
    {
        return $this->inTenant($tenant, fn (): string => BankAccount::create([
            'name' => 'Cuenta '.uniqid(),
            'bank_account_type_id' => BankAccountType::BANK,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_primary' => false,
            'is_active' => true,
        ])->id);
    }

    public function test_has_cost_without_cost_amount_returns_422(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $bankAccountId = $this->makeBankAccountId($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', array_merge($this->basePayload(), [
                'has_cost' => true,
                'bank_account_id' => $bankAccountId,
                // cost_amount intentionally omitted
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cost_amount');
    }

    public function test_cross_tenant_bank_account_id_returns_422(): void
    {
        [$tenantA, $tokenA] = $this->adminTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B '.uniqid());
        $this->seedTenant($tenantB);

        $foreignBankAccountId = $this->makeBankAccountId($tenantB);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/amenity', array_merge($this->basePayload(), [
                'has_cost' => true,
                'cost_amount' => 15000,
                'bank_account_id' => $foreignBankAccountId,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_account_id');
    }

    public function test_amenity_with_cost_and_valid_own_tenant_bank_account_is_created(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $bankAccountId = $this->makeBankAccountId($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', array_merge($this->basePayload(), [
                'has_cost' => true,
                'cost_amount' => 15000,
                'bank_account_id' => $bankAccountId,
            ]))
            ->assertStatus(201);
    }

    public function test_invalid_target_type_returns_422(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', array_merge($this->basePayload(), [
                'target_type' => 'bogus',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_type');
    }

    public function test_valid_target_type_with_selector_is_created(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', array_merge($this->basePayload(), [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [(string) Str::uuid()]],
            ]))
            ->assertStatus(201);
    }
}
