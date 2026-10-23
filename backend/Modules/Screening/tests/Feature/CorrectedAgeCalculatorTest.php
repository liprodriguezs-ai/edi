<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S6 (odd/tasks/edi-legacy-parity.md) — the standalone "Edad corregida"
 * calculator. It must apply the SAME rule the Prueba EDI uses (AgeResolver:
 * corrected only below 37 weeks and under 24 months), never its own copy.
 */
final class CorrectedAgeCalculatorTest extends TestCase
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
    private function calculatorUser(): array
    {
        [$tenant] = $this->twoTenants();
        (new InstrumentSeeder)->run();

        $role = Role::create(['name' => 'calc-'.Str::random(8), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::findOrCreate('read.corrected_age', 'api'));
        $user = $this->makeUser($tenant);
        $user->assignRole($role->name);

        return [$tenant, $user->createToken('calc', ['read.corrected_age'])->plainTextToken];
    }

    public function test_premature_child_at_three_months_gets_a_corrected_age_and_group(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        // 2025-01-01 -> 2025-04-01 = 90 calendar days; 32 weeks -> 90 - 8*7 = 34 days.
        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2025-01-01&gestational_weeks=32&reference_date=2025-04-01')
            ->assertOk();

        $response->assertJsonPath('data.applies', true);
        $response->assertJsonPath('data.reason', null);
        $response->assertJsonPath('data.reference_date', '2025-04-01');
        $response->assertJsonPath('data.chronological.days', 90);
        $response->assertJsonPath('data.chronological.months', 3);
        $response->assertJsonPath('data.chronological.remaining_days', 0);
        $response->assertJsonPath('data.corrected.days', 34);
        $response->assertJsonPath('data.corrected.months', 1);
        $response->assertJsonPath('data.corrected.remaining_days', 4);
        $response->assertJsonPath('data.chronological_age_group.code', 'G3');
        $response->assertJsonPath('data.corrected_age_group.code', 'G1');
    }

    public function test_a_young_premature_baby_is_corrected_and_told_when_the_test_applies(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        // 45 days at 34 weeks: corrected 45 - 42 = 3 days, under the 1-month minimum.
        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2025-01-01&gestational_weeks=34&reference_date=2025-02-15')
            ->assertOk();

        $response->assertJsonPath('data.applies', true);
        $response->assertJsonPath('data.reason', null);
        $response->assertJsonPath('data.corrected.days', 3);
        $response->assertJsonPath('data.corrected_age_group', null);
        $response->assertJsonPath('data.ready_from', '2025-03-14');
    }

    public function test_a_negative_corrected_age_is_not_split_into_negative_months(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        // 20 days at 30 weeks: corrected 20 - 70 = -50 days.
        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2025-01-01&gestational_weeks=30&reference_date=2025-01-21')
            ->assertOk();

        $response->assertJsonPath('data.corrected.days', -50);
        $response->assertJsonPath('data.corrected.months', 0);
        $response->assertJsonPath('data.corrected.remaining_days', -50);
        $response->assertJsonPath('data.ready_from', '2025-04-11');
    }

    public function test_term_birth_does_not_apply(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2025-01-01&gestational_weeks=37&reference_date=2025-04-01')
            ->assertOk();

        $response->assertJsonPath('data.applies', false);
        $response->assertJsonPath('data.reason', 'term_birth');
        $response->assertJsonPath('data.corrected', null);
        $response->assertJsonPath('data.corrected_age_group', null);
        $response->assertJsonPath('data.chronological_age_group.code', 'G3');
    }

    public function test_two_years_or_older_does_not_apply(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2023-01-01&gestational_weeks=30&reference_date=2025-02-01')
            ->assertOk();

        $response->assertJsonPath('data.applies', false);
        $response->assertJsonPath('data.reason', 'over_two_years');
        $response->assertJsonPath('data.corrected', null);
    }

    public function test_reference_date_defaults_to_tenant_today(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        $today = $this->inTenant($tenant, fn () => TenantTime::today());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2024-01-01&gestational_weeks=39')
            ->assertOk()
            ->assertJsonPath('data.reference_date', $today);
    }

    public function test_invalid_input_is_rejected(): void
    {
        [$tenant, $token] = $this->calculatorUser();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2025-05-01&gestational_weeks=50&reference_date=2025-04-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gestational_weeks', 'reference_date']);
    }

    public function test_requires_read_corrected_age(): void
    {
        [$tenant] = $this->twoTenants();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('nobody', [])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/screening/corrected-age?birth_date=2025-01-01&gestational_weeks=32')
            ->assertStatus(403);
    }
}
