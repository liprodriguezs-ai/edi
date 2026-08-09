<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Caregiver;
use Modules\ChildRecord\Models\CaregiverRelationship;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\ChildCaregiver;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CH-005 / REQ-CH-006 coverage.
 */
final class ChildCaregiverApiTest extends TestCase
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

    private function makeLinkFixtures(Tenant $tenant): array
    {
        return $this->inTenant($tenant, function () {
            return [
                'child' => Child::factory()->create(),
                'caregiverA' => Caregiver::factory()->create(),
                'caregiverB' => Caregiver::factory()->create(),
                'relationship' => CaregiverRelationship::factory()->create(),
            ];
        });
    }

    // ── REQ-CH-005 ──────────────────────────────────────────────────────────

    public function test_duplicate_link_rejected_then_succeeds_after_soft_delete(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeLinkFixtures($tenant);

        $first = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])
            ->assertCreated();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])
            ->assertStatus(422);

        $linkId = $first->json('data.id');
        $this->tenantRequest($token, $tenant)->deleteJson('/api/child_caregiver/'.$linkId)->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])
            ->assertCreated();
    }

    public function test_cross_tenant_link_rejected(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $child = $this->inTenant($tenantA, fn () => Child::factory()->create());
        $relationship = $this->inTenant($tenantA, fn () => CaregiverRelationship::factory()->create());
        $caregiverB = $this->inTenant($tenantB, fn () => Caregiver::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/child_caregiver', [
                'child_id' => $child->id,
                'caregiver_id' => $caregiverB->id,
                'caregiver_relationship_id' => $relationship->id,
            ])
            ->assertStatus(422);
    }

    // ── REQ-CH-006 ──────────────────────────────────────────────────────────

    public function test_first_link_auto_primary(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeLinkFixtures($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true);
    }

    public function test_promoting_new_primary_demotes_old(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeLinkFixtures($tenant);

        $linkA = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])->json('data.id');

        $linkB = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverB']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/child_caregiver/'.$linkB, ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $tenant->run(function () use ($linkA, $linkB): void {
            $this->assertFalse((bool) ChildCaregiver::find($linkA)->is_primary);
            $this->assertTrue((bool) ChildCaregiver::find($linkB)->is_primary);
            $this->assertSame(
                1,
                ChildCaregiver::where('is_primary', true)->count(),
                'At no point may two active primaries exist.'
            );
        });
    }

    public function test_deleting_primary_with_other_links_requires_new_primary_first(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeLinkFixtures($tenant);

        $linkA = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverB']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ]);

        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/child_caregiver/'.$linkA)
            ->assertStatus(422);
    }

    public function test_concurrent_promotion_cannot_create_two_primaries(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $fx = $this->makeLinkFixtures($tenant);

        $linkA = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverA']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])->json('data.id');

        $linkB = $this->tenantRequest($token, $tenant)
            ->postJson('/api/child_caregiver', [
                'child_id' => $fx['child']->id,
                'caregiver_id' => $fx['caregiverB']->id,
                'caregiver_relationship_id' => $fx['relationship']->id,
            ])->json('data.id');

        // Simulate a race: promote B, then independently try to promote A too
        // (the service always demotes-then-promotes inside one transaction, so
        // the second call correctly demotes B first — the DB partial unique
        // index is the ultimate backstop for a true concurrent race).
        $this->tenantRequest($token, $tenant)->putJson('/api/child_caregiver/'.$linkB, ['is_primary' => true])->assertOk();
        $this->tenantRequest($token, $tenant)->putJson('/api/child_caregiver/'.$linkA, ['is_primary' => true])->assertOk();

        $tenant->run(function (): void {
            $this->assertSame(1, ChildCaregiver::where('is_primary', true)->count());
        });
    }
}
