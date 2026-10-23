<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CH-008 coverage.
 */
final class VisitApiTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** Civil "today" of the test tenants (they keep the default zone), never the UTC day. */
    private function tenantToday(): string
    {
        return TenantTime::today(Tenant::DEFAULT_TIMEZONE);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeFixtures(Tenant $tenant): array
    {
        return $this->inTenant($tenant, function () {
            $healthCenter = HealthCenter::factory()->create([
                'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
            ]);

            return [
                'child' => Child::factory()->create(['health_center_id' => $healthCenter->id]),
                'healthCenter' => $healthCenter,
                'practitioner' => PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]),
            ];
        });
    }

    public function test_receipt_number_generated_and_sequential_per_tenant_year(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeFixtures($tenant);

        $payload = fn () => [
            'child_id' => $fx['child']->id,
            'health_center_id' => $fx['healthCenter']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'visit_date' => $this->tenantToday(),
        ];

        $r1 = $this->tenantRequest($token, $tenant)->postJson('/api/visit', $payload())->assertCreated();
        $r2 = $this->tenantRequest($token, $tenant)->postJson('/api/visit', $payload())->assertCreated();

        $year = now()->format('Y');
        $r1->assertJsonPath('data.receipt_number', "V-{$year}-000001");
        $r2->assertJsonPath('data.receipt_number', "V-{$year}-000002");
    }

    public function test_receipt_sequence_is_independent_per_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $fxA = $this->makeFixtures($tenantA);
        $fxB = $this->makeFixtures($tenantB);
        $userB = $this->makeUser($tenantB);
        $tokenB = $userB->createToken('test', ['*'])->plainTextToken;

        $year = now()->format('Y');

        $this->tenantRequest($tokenA, $tenantA)->postJson('/api/visit', [
            'child_id' => $fxA['child']->id,
            'health_center_id' => $fxA['healthCenter']->id,
            'practitioner_id' => $fxA['practitioner']->id,
            'visit_date' => $this->tenantToday(),
        ])->assertCreated()->assertJsonPath('data.receipt_number', "V-{$year}-000001");

        $this->tenantRequest($tokenB, $tenantB)->postJson('/api/visit', [
            'child_id' => $fxB['child']->id,
            'health_center_id' => $fxB['healthCenter']->id,
            'practitioner_id' => $fxB['practitioner']->id,
            'visit_date' => $this->tenantToday(),
        ])->assertCreated()->assertJsonPath('data.receipt_number', "V-{$year}-000001");
    }

    public function test_referenced_entities_must_share_the_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $fxA = $this->makeFixtures($tenantA);
        $fxB = $this->makeFixtures($tenantB);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/visit', [
                'child_id' => $fxA['child']->id,
                'health_center_id' => $fxA['healthCenter']->id,
                'practitioner_id' => $fxB['practitioner']->id,
                'visit_date' => $this->tenantToday(),
            ])
            ->assertStatus(422);
    }

    public function test_receipt_number_is_immutable_on_update(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeFixtures($tenant);

        $visitId = $this->tenantRequest($token, $tenant)->postJson('/api/visit', [
            'child_id' => $fx['child']->id,
            'health_center_id' => $fx['healthCenter']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'visit_date' => $this->tenantToday(),
        ])->json('data.id');

        $originalReceipt = $tenant->run(fn () => Visit::find($visitId)->receipt_number);

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/visit/'.$visitId, [
                'receipt_number' => 'V-2000-999999',
                'physical_receipt_number' => 'FOLIO-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $originalReceipt)
            ->assertJsonPath('data.physical_receipt_number', 'FOLIO-001');
    }

    public function test_child_visit_history_sorted_desc_and_filtered_by_child(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeFixtures($tenant);

        $this->tenantRequest($token, $tenant)->postJson('/api/visit', [
            'child_id' => $fx['child']->id,
            'health_center_id' => $fx['healthCenter']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'visit_date' => now()->subDays(5)->format('Y-m-d'),
        ])->assertCreated();

        $this->tenantRequest($token, $tenant)->postJson('/api/visit', [
            'child_id' => $fx['child']->id,
            'health_center_id' => $fx['healthCenter']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'visit_date' => $this->tenantToday(),
        ])->assertCreated();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/visit/search', [
                'filters' => [['field' => 'child_id', 'operator' => '=', 'value' => $fx['child']->id]],
                'sort' => [['field' => 'visit_date', 'direction' => 'desc']],
            ])
            ->assertOk();

        $dates = collect($response->json('data'))->pluck('visit_date')->all();
        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all());
    }
}
