<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Child;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * "Today" is the tenant's civil day, not the server's UTC day: at
 * 2026-10-21T03:00Z it is still Oct 20, 21:00 in Mexico City, so a visit dated
 * Oct 21 is in the tenant's future and must be rejected.
 */
final class TenantCivilTodayTest extends TestCase
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

    /** @return array{0: Tenant, 1: string, 2: array<string, mixed>} */
    private function scenario(): array
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $tenant->update(['timezone' => 'America/Mexico_City']);
        $token = $this->makeUser($tenant)->createToken('test', ['*'])->plainTextToken;

        $fx = $this->inTenant($tenant, function (): array {
            $healthCenter = HealthCenter::factory()->create([
                'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
            ]);

            return [
                'child_id' => Child::factory()->create([
                    'health_center_id' => $healthCenter->id,
                    'birth_date' => '2025-01-10',
                ])->id,
                'health_center_id' => $healthCenter->id,
                'practitioner_id' => PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id])->id,
            ];
        });

        $this->travelTo(Carbon::parse('2026-10-21T03:00:00Z'));

        return [$tenant, $token, $fx];
    }

    public function test_a_visit_dated_on_the_utc_day_but_tenant_tomorrow_is_rejected(): void
    {
        [$tenant, $token, $fx] = $this->scenario();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visit', $fx + ['visit_date' => '2026-10-21'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visit_date']);
    }

    public function test_a_visit_dated_on_the_tenant_today_is_accepted(): void
    {
        [$tenant, $token, $fx] = $this->scenario();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visit', $fx + ['visit_date' => '2026-10-20'])
            ->assertCreated()
            ->assertJsonPath('data.visit_date', '2026-10-20');
    }

    public function test_a_birth_date_on_the_tenant_tomorrow_is_rejected(): void
    {
        [$tenant, $token, $fx] = $this->scenario();

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/child/'.$fx['child_id'], ['birth_date' => '2026-10-21'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date']);

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/child/'.$fx['child_id'], ['birth_date' => '2026-10-20'])
            ->assertOk()
            ->assertJsonPath('data.birth_date', '2026-10-20');
    }

    public function test_date_only_fields_serialize_as_civil_dates_and_instants_as_utc(): void
    {
        [$tenant, $token, $fx] = $this->scenario();
        $this->inTenant($tenant, fn () => Child::whereKey($fx['child_id'])->update(['birth_date' => '2020-05-10']));

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/child/'.$fx['child_id'])
            ->assertOk()
            ->assertJsonPath('data.birth_date', '2020-05-10');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', (string) $response->json('data.created_at'));
    }
}
