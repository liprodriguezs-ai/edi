<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Services\AmenityVisibilityService;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * A1.3 — AmenityVisibilityService, the inert read-only resolution layer.
 * Nothing reads from this service outside these tests yet (wired in A2/B).
 */
final class AmenityVisibilityServiceTest extends TestCase
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

    private function tenant(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);

        return $tenant;
    }

    private function unit(array $overrides = []): Unit
    {
        return Unit::factory()->create(array_merge([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'number' => (string) random_int(100000, 999999),
        ], $overrides));
    }

    private function attachResident(Unit $unit, User $user): void
    {
        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
            'is_billing_owner' => true,
        ]);
    }

    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ], $overrides));
    }

    public function test_amenities_sharing_one_target_resolve_it_exactly_once(): void
    {
        $tenant = $this->tenant();

        [$userId, $towerId] = $this->inTenant($tenant, function (): array {
            $tower = Tower::factory()->create();
            $unit = $this->unit(['tower_id' => $tower->id]);
            $user = User::factory()->create();
            $this->attachResident($unit, $user);

            $this->amenity(['target_type' => 'by_tower', 'target_selector' => ['tower_ids' => [$tower->id]]]);
            $this->amenity(['target_type' => 'by_tower', 'target_selector' => ['tower_ids' => [$tower->id]]]);
            $this->amenity(['target_type' => 'by_tower', 'target_selector' => ['tower_ids' => [$tower->id]]]);

            return [$user->id, $tower->id];
        });

        $this->inTenant($tenant, function () use ($userId, $towerId): void {
            $resolver = $this->mock(TargetResolver::class);
            $resolver->shouldReceive('resolveUnitIds')
                ->once()
                ->with('by_tower', ['tower_ids' => [$towerId]])
                ->andReturn(new Collection([]));

            $service = app(AmenityVisibilityService::class);
            $service->accessibleAmenityIds($userId);
        });
    }

    public function test_trivial_targets_never_call_the_resolver(): void
    {
        $tenant = $this->tenant();

        $userId = $this->inTenant($tenant, function (): string {
            $user = User::factory()->create();
            $this->amenity(['target_type' => 'all']);
            // NOT NULL default('all') blocks a genuinely null row through the
            // model — 'all' covers both the explicit and default-column cases.
            $this->amenity();

            return $user->id;
        });

        $this->inTenant($tenant, function () use ($userId): void {
            $resolver = $this->mock(TargetResolver::class);
            $resolver->shouldNotReceive('resolveUnitIds');

            $service = app(AmenityVisibilityService::class);
            $ids = $service->accessibleAmenityIds($userId);

            $this->assertCount(2, $ids);
        });
    }

    public function test_multi_unit_resident_sees_amenity_when_only_one_unit_is_in_target(): void
    {
        $tenant = $this->tenant();

        [$userId, $amenityId] = $this->inTenant($tenant, function (): array {
            $towerA = Tower::factory()->create();
            $towerB = Tower::factory()->create();
            $unitOutOfTarget = $this->unit(['tower_id' => $towerA->id]);
            $unitInTarget = $this->unit(['tower_id' => $towerB->id]);

            $user = User::factory()->create();
            $this->attachResident($unitOutOfTarget, $user);
            $this->attachResident($unitInTarget, $user);

            $amenity = $this->amenity(['target_type' => 'by_tower', 'target_selector' => ['tower_ids' => [$towerB->id]]]);

            return [$user->id, $amenity->id];
        });

        $this->inTenant($tenant, function () use ($userId, $amenityId): void {
            $service = app(AmenityVisibilityService::class);
            $amenity = Amenity::findOrFail($amenityId);

            $this->assertTrue($service->userCanSeeAmenity($amenity, $userId));
        });
    }

    public function test_by_feature_requires_all_selected_features(): void
    {
        $tenant = $this->tenant();

        [$userId, $amenityId] = $this->inTenant($tenant, function (): array {
            $featureA = UnitFeature::factory()->create();
            $featureB = UnitFeature::factory()->create();

            $unit = $this->unit();
            $unit->features()->attach($featureA->id); // only ONE of the two required features

            $user = User::factory()->create();
            $this->attachResident($unit, $user);

            $amenity = $this->amenity([
                'target_type' => 'by_feature',
                'target_selector' => ['unit_feature_ids' => [$featureA->id, $featureB->id]],
            ]);

            return [$user->id, $amenity->id];
        });

        $this->inTenant($tenant, function () use ($userId, $amenityId): void {
            $service = app(AmenityVisibilityService::class);
            $amenity = Amenity::findOrFail($amenityId);

            $this->assertFalse($service->userCanSeeAmenity($amenity, $userId));
        });
    }

    public function test_unknown_target_type_is_hidden_without_throwing(): void
    {
        $tenant = $this->tenant();

        [$userId, $amenityId] = $this->inTenant($tenant, function (): array {
            $unit = $this->unit();
            $user = User::factory()->create();
            $this->attachResident($unit, $user);

            $amenity = $this->amenity();
            // Simulate a somehow-persisted row bypassing FormRequest validation.
            DB::table('amenity')->where('id', $amenity->id)->update(['target_type' => 'bogus']);

            return [$user->id, $amenity->id];
        });

        $this->inTenant($tenant, function () use ($userId, $amenityId): void {
            $service = app(AmenityVisibilityService::class);
            $amenity = Amenity::findOrFail($amenityId);

            $this->assertFalse($service->userCanSeeAmenity($amenity, $userId));
        });
    }
}
