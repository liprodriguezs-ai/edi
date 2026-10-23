<?php

declare(strict_types=1);

namespace Modules\Stimulation\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Stimulation\Models\BodyZone;
use Modules\Stimulation\Models\MassageMovement;
use Modules\Stimulation\Models\StimulationDynamic;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationSession;
use Modules\Stimulation\Models\StimulationSessionMovement;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SS-001 / REQ-SS-002 / REQ-SS-003 coverage.
 */
final class StimulationSessionApiTest extends TestCase
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

    private function makePractitioner(): PractitionerProfile
    {
        $municipality = HealthCenterFactory::defaultMunicipality();
        $healthCenter = HealthCenter::factory()->create(['municipality_id' => $municipality->id]);

        return PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);
    }

    private function makeChild(): Child
    {
        return Child::factory()->create();
    }

    private function adminToken(Tenant $tenant): string
    {
        $user = $this->makeUser($tenant);

        return $user->createToken('test', ['*'])->plainTextToken;
    }

    // ── REQ-SS-001 ──────────────────────────────────────────────────────────

    public function test_create_minimal_individual_session(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);

        [$child, $practitioner] = $this->inTenant($tenant, fn () => [$this->makeChild(), $this->makePractitioner()]);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.child_id', $child->id);
        $response->assertJsonPath('data.integration_material', null);

        $tenant->run(function () use ($tenant): void {
            $this->assertSame($tenant->id, StimulationSession::first()->tenant_id);
        });
    }

    public function test_catalog_reference_from_another_tenant_is_rejected(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        [$child, $practitioner] = $this->inTenant($tenantA, fn () => [$this->makeChild(), $this->makePractitioner()]);
        $otherDynamic = $this->inTenant($tenantB, fn () => StimulationDynamic::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
                'integration_dynamic_id' => $otherDynamic->id,
            ])
            ->assertStatus(422);
    }

    public function test_future_session_date_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$child, $practitioner] = $this->inTenant($tenant, fn () => [$this->makeChild(), $this->makePractitioner()]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'session_date' => now()->addDay()->toDateString(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertStatus(422);
    }

    // ── REQ-SS-002 ──────────────────────────────────────────────────────────

    public function test_subjectless_and_double_subject_payloads_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$child, $practitioner] = $this->inTenant($tenant, fn () => [$this->makeChild(), $this->makePractitioner()]);
        $group = $this->inTenant($tenant, fn () => StimulationGroup::factory()->create());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertStatus(422);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'stimulation_group_id' => $group->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertStatus(422);
    }

    public function test_visit_coherence(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);

        [$child, $otherChild, $practitioner, $healthCenter] = $this->inTenant($tenant, function () {
            $municipality = HealthCenterFactory::defaultMunicipality();
            $healthCenter = HealthCenter::factory()->create(['municipality_id' => $municipality->id]);
            $practitioner = PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);

            return [$this->makeChild(), $this->makeChild(), $practitioner, $healthCenter];
        });

        $otherChildVisit = $this->inTenant($tenant, fn () => Visit::factory()->create([
            'child_id' => $otherChild->id,
            'health_center_id' => $healthCenter->id,
            'practitioner_id' => $practitioner->id,
        ]));

        // visit belongs to a different child
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'visit_id' => $otherChildVisit->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertStatus(422);

        $group = $this->inTenant($tenant, fn () => StimulationGroup::factory()->create());
        $childVisit = $this->inTenant($tenant, fn () => Visit::factory()->create([
            'child_id' => $child->id,
            'health_center_id' => $healthCenter->id,
            'practitioner_id' => $practitioner->id,
        ]));

        // visit_id on a group session
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'stimulation_group_id' => $group->id,
                'visit_id' => $childVisit->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertStatus(422);
    }

    public function test_group_session_accepted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        $practitioner = $this->inTenant($tenant, fn () => $this->makePractitioner());
        $group = $this->inTenant($tenant, fn () => StimulationGroup::factory()->create());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'stimulation_group_id' => $group->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
            ])
            ->assertCreated();
    }

    // ── REQ-SS-003 ──────────────────────────────────────────────────────────

    public function test_sync_lifecycle(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$child, $practitioner] = $this->inTenant($tenant, fn () => [$this->makeChild(), $this->makePractitioner()]);

        [$zoneA, $zoneB, $zoneC, $movementA, $movementB, $movementC] = $this->inTenant($tenant, fn () => [
            BodyZone::factory()->create(),
            BodyZone::factory()->create(),
            BodyZone::factory()->create(),
            MassageMovement::factory()->create(),
            MassageMovement::factory()->create(),
            MassageMovement::factory()->create(),
        ]);

        $create = $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
                'movements' => [
                    ['body_zone_id' => $zoneA->id, 'massage_movement_id' => $movementA->id],
                    ['body_zone_id' => $zoneB->id, 'massage_movement_id' => $movementB->id],
                    ['body_zone_id' => $zoneC->id, 'massage_movement_id' => $movementC->id],
                ],
                'relaxation_zones' => [
                    ['body_zone_id' => $zoneA->id],
                    ['body_zone_id' => $zoneB->id],
                ],
            ])
            ->assertCreated();

        $sessionId = $create->json('data.id');

        // update: remove pair A, add a new pair with zoneC + movementA
        $this->tenantRequest($token, $tenant)
            ->putJson('/api/stimulation_session/'.$sessionId, [
                'movements' => [
                    ['body_zone_id' => $zoneB->id, 'massage_movement_id' => $movementB->id],
                    ['body_zone_id' => $zoneC->id, 'massage_movement_id' => $movementC->id],
                    ['body_zone_id' => $zoneC->id, 'massage_movement_id' => $movementA->id],
                ],
            ])
            ->assertOk();

        $tenant->run(function () use ($sessionId, $zoneA, $movementA, $zoneB, $movementB, $zoneC, $movementC): void {
            $removed = StimulationSessionMovement::withTrashed()
                ->where('session_id', $sessionId)
                ->where('body_zone_id', $zoneA->id)
                ->where('massage_movement_id', $movementA->id)
                ->first();
            $this->assertNotNull($removed);
            $this->assertTrue($removed->trashed());

            $this->assertTrue(
                StimulationSessionMovement::where('session_id', $sessionId)
                    ->where('body_zone_id', $zoneC->id)->where('massage_movement_id', $movementA->id)->exists()
            );
            $this->assertTrue(
                StimulationSessionMovement::where('session_id', $sessionId)
                    ->where('body_zone_id', $zoneB->id)->where('massage_movement_id', $movementB->id)->exists()
            );
            $this->assertTrue(
                StimulationSessionMovement::where('session_id', $sessionId)
                    ->where('body_zone_id', $zoneC->id)->where('massage_movement_id', $movementC->id)->exists()
            );
        });

        // re-add the removed pair -> restores instead of duplicating
        $this->tenantRequest($token, $tenant)
            ->putJson('/api/stimulation_session/'.$sessionId, [
                'movements' => [
                    ['body_zone_id' => $zoneA->id, 'massage_movement_id' => $movementA->id],
                    ['body_zone_id' => $zoneB->id, 'massage_movement_id' => $movementB->id],
                    ['body_zone_id' => $zoneC->id, 'massage_movement_id' => $movementC->id],
                ],
            ])
            ->assertOk();

        $tenant->run(function () use ($sessionId, $zoneA, $movementA): void {
            $this->assertSame(
                1,
                StimulationSessionMovement::withTrashed()
                    ->where('session_id', $sessionId)
                    ->where('body_zone_id', $zoneA->id)
                    ->where('massage_movement_id', $movementA->id)
                    ->count()
            );
            $this->assertTrue(
                StimulationSessionMovement::where('session_id', $sessionId)
                    ->where('body_zone_id', $zoneA->id)->where('massage_movement_id', $movementA->id)->exists()
            );
        });
    }

    public function test_duplicate_pair_in_payload_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$child, $practitioner] = $this->inTenant($tenant, fn () => [$this->makeChild(), $this->makePractitioner()]);
        [$zone, $movement] = $this->inTenant($tenant, fn () => [BodyZone::factory()->create(), MassageMovement::factory()->create()]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
                'movements' => [
                    ['body_zone_id' => $zone->id, 'massage_movement_id' => $movement->id],
                    ['body_zone_id' => $zone->id, 'massage_movement_id' => $movement->id],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_cross_tenant_body_zone_rejected(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        [$child, $practitioner] = $this->inTenant($tenantA, fn () => [$this->makeChild(), $this->makePractitioner()]);
        $otherZone = $this->inTenant($tenantB, fn () => BodyZone::factory()->create());
        $movement = $this->inTenant($tenantA, fn () => MassageMovement::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/stimulation_session', [
                'child_id' => $child->id,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
                'movements' => [
                    ['body_zone_id' => $otherZone->id, 'massage_movement_id' => $movement->id],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_db_check_backstop_rejects_row_without_subject(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $practitioner = $this->inTenant($tenant, fn () => $this->makePractitioner());

        $this->expectException(QueryException::class);

        $tenant->run(function () use ($tenant, $practitioner): void {
            DB::table('stimulation_session')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'child_id' => null,
                'stimulation_group_id' => null,
                'session_date' => $this->tenantToday(),
                'practitioner_id' => $practitioner->id,
                'created_at' => now(),
            ]);
        });
    }
}
