<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Sex;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CH-001 / REQ-CH-002 / REQ-CH-003 / REQ-XC-001 coverage.
 * REQ-XC-005 (audit trail) lives in ChildAuditTest.php — it needs real
 * commits (Audit::save() defers to DB::afterCommit()), which is incompatible
 * with this class's transactional per-test isolation.
 */
final class ChildApiTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeHealthCenter(Tenant $tenant): HealthCenter
    {
        return $this->inTenant($tenant, fn () => HealthCenter::factory()->create([
            'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
        ]));
    }

    private function validPayload(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sofía',
            'last_name_paternal' => 'García',
            'last_name_maternal' => 'López',
            'birth_date' => '2022-03-05',
            'sex_id' => Sex::FEMALE,
            'health_center_id' => $this->makeHealthCenter($tenant)->id,
        ], $overrides);
    }

    // ── REQ-CH-001 ──────────────────────────────────────────────────────────

    public function test_create_child_happy_path(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $this->validPayload($tenant))
            ->assertCreated();
    }

    public function test_future_birth_date_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $this->validPayload($tenant, ['birth_date' => now()->addDay()->format('Y-m-d')]))
            ->assertStatus(422);
    }

    public function test_sex_must_be_a_central_catalog_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $this->validPayload($tenant, ['sex_id' => (string) Str::uuid7()]))
            ->assertStatus(422);
    }

    public function test_last_name_maternal_is_nullable(): void
    {
        // D-13: single-surname registrations must be accepted.
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $payload = $this->validPayload($tenant);
        unset($payload['last_name_maternal']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $payload)
            ->assertCreated()
            ->assertJsonPath('data.last_name_maternal', null);
    }

    // ── REQ-CH-002 ──────────────────────────────────────────────────────────

    public function test_lowercase_curp_is_normalized_then_accepted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // Consistent with birth_date=2018-05-14 and sex=female (M).
        $payload = $this->validPayload($tenant, [
            'birth_date' => '2018-05-14',
            'sex_id' => Sex::FEMALE,
            'curp' => 'gaso180514mdfrpn09',
        ]);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $payload)
            ->assertCreated();

        $response->assertJsonPath('data.curp', 'GASO180514MDFRPN09');
        $response->assertJsonPath('data.curp_mismatch', false);
    }

    public function test_malformed_curp_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $this->validPayload($tenant, ['curp' => 'XXXX123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['curp']);
    }

    /**
     * D-16 overrides the original REQ-CH-002 scenario wording ("fails with
     * 422"): a CURP/birth_date/sex mismatch is a WARNING, never a blocking
     * error. The record persists with curp_mismatch = true so the UI can
     * flag it for review.
     */
    public function test_curp_inconsistent_with_birth_date_is_a_warning_not_a_block(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $payload = $this->validPayload($tenant, [
            'birth_date' => '2024-03-05',
            'sex_id' => Sex::FEMALE,
            'curp' => 'GASO240306MDFRPN09', // date segment encodes 240306, not 240305
        ]);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $payload)
            ->assertCreated();

        $response->assertJsonPath('data.curp_mismatch', true);

        $tenant->run(function (): void {
            $this->assertTrue((bool) Child::first()->curp_mismatch);
        });
    }

    // ── REQ-CH-003 ──────────────────────────────────────────────────────────

    public function test_duplicate_curp_in_tenant_rejected_but_allowed_in_another_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $this->inTenant($tenantA, function (): void {
            Child::factory()->create(['curp' => 'GASO180514MDFRPN09']);
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/child', $this->validPayload($tenantA, ['curp' => 'GASO180514MDFRPN09', 'birth_date' => '2018-05-14']))
            ->assertStatus(422);

        $userB = $this->makeUser($tenantB);
        $tokenB = $userB->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/child', $this->validPayload($tenantB, ['curp' => 'GASO180514MDFRPN09', 'birth_date' => '2018-05-14']))
            ->assertCreated();
    }

    public function test_soft_deleted_child_frees_its_curp(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->inTenant($tenant, function (): void {
            $child = Child::factory()->create(['curp' => 'GASO180514MDFRPN09']);
            $child->delete();
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child', $this->validPayload($tenant, ['curp' => 'GASO180514MDFRPN09', 'birth_date' => '2018-05-14']))
            ->assertCreated();
    }

    // ── REQ-XC-001 ──────────────────────────────────────────────────────────

    public function test_cross_tenant_show_is_404(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $childB = $this->inTenant($tenantB, fn () => Child::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson('/api/child/'.$childB->id)
            ->assertStatus(404);
    }
}
