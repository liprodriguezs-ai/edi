<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S18 — GET amenity_reservation/occupancy: a READ-ONLY, ANONYMIZED cluster-
 * wide occupancy calendar for the oversight committee (transparency without
 * PII). Mirrors AmenityAvailabilityEndpointTest's tenant/flag setup
 * conventions and AmenityReservationReadScopingTest's unit/amenity helpers.
 */
final class AmenityOccupancyEndpointTest extends TestCase
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

    private function tenantWithFlagEnabled(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    private function unit(): Unit
    {
        return Unit::factory()->create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'number' => (string) random_int(100000, 999999),
        ]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function oversightToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('oversight', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    private function byDayAmenity(): Amenity
    {
        return Amenity::create([
            'name' => 'Salón de eventos '.uniqid(),
            'capacity' => 40,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ]);
    }

    private function bySlotAmenity(int $capacity = 2): Amenity
    {
        return Amenity::create([
            'name' => 'Alberca '.uniqid(),
            'capacity' => $capacity,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_SLOT,
        ]);
    }

    private function reservation(array $overrides): AmenityReservation
    {
        return AmenityReservation::create(array_merge([
            'user_id' => (string) Str::uuid(),
            'reservation_status_id' => ReservationStatus::CONFIRMED,
            'holds_slot' => true,
            'amount_outstanding' => 0,
        ], $overrides));
    }

    // =========================================================================
    // by_day occupancy
    // =========================================================================

    public function test_occupancy_reports_a_held_by_day_reservation_as_occupied_one_of_one(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->oversightToken($tenant);
        $date = now()->addDays(3)->toDateString();

        $amenityId = $this->inTenant($tenant, function () use ($date): string {
            $amenity = $this->byDayAmenity();
            $this->reservation([
                'amenity_id' => $amenity->id,
                'unit_id' => $this->unit()->id,
                'reservation_date' => $date,
            ]);

            return $amenity->id;
        });

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertOk();

        $entries = collect($response->json('data'));
        $entry = $entries->firstWhere('amenity_id', $amenityId);

        $this->assertNotNull($entry, 'Expected an occupancy entry for the held by_day reservation.');
        $this->assertSame($date, $entry['reservation_date']);
        $this->assertNull($entry['slot_id']);
        $this->assertNull($entry['start_at']);
        $this->assertNull($entry['end_at']);
        $this->assertSame(1, $entry['occupied']);
        $this->assertSame(1, $entry['capacity']);
    }

    public function test_occupancy_never_leaks_unit_user_or_reservation_identifiers(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->oversightToken($tenant);
        $date = now()->addDays(3)->toDateString();

        $this->inTenant($tenant, function () use ($date): void {
            $amenity = $this->byDayAmenity();
            $this->reservation([
                'amenity_id' => $amenity->id,
                'unit_id' => $this->unit()->id,
                'reservation_date' => $date,
            ]);
        });

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertOk();

        $entries = collect($response->json('data'));
        $this->assertGreaterThan(0, $entries->count());

        foreach ($entries as $entry) {
            $keys = array_keys($entry);
            sort($keys);

            $this->assertSame(
                ['amenity_id', 'amenity_name', 'capacity', 'end_at', 'occupied', 'reservation_date', 'slot_id', 'start_at'],
                $keys,
            );
            $this->assertArrayNotHasKey('unit_id', $entry);
            $this->assertArrayNotHasKey('user_id', $entry);
            $this->assertArrayNotHasKey('id', $entry);
            $this->assertArrayNotHasKey('reservation_id', $entry);
        }
    }

    public function test_a_released_reservation_does_not_count_as_occupied(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->oversightToken($tenant);
        $date = now()->addDays(3)->toDateString();

        $amenityId = $this->inTenant($tenant, function () use ($date): string {
            $amenity = $this->byDayAmenity();
            $this->reservation([
                'amenity_id' => $amenity->id,
                'unit_id' => $this->unit()->id,
                'reservation_date' => $date,
                'holds_slot' => false,
                'reservation_status_id' => ReservationStatus::CANCELLED,
            ]);

            return $amenity->id;
        });

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertOk();

        $entries = collect($response->json('data'));
        $this->assertNull($entries->firstWhere('amenity_id', $amenityId), 'A released (holds_slot=false) reservation must not appear as occupied.');
    }

    // =========================================================================
    // by_slot occupancy (n of m capacity)
    // =========================================================================

    public function test_occupancy_counts_by_slot_reservations_against_slot_capacity(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->oversightToken($tenant);
        $date = now()->addDays(3)->toDateString();

        $context = $this->inTenant($tenant, function () use ($date): array {
            $amenity = $this->bySlotAmenity(capacity: 3);
            $slot = AmenitySlot::create([
                'amenity_id' => $amenity->id,
                'weekday' => Carbon::parse($date)->dayOfWeek,
                'start_time' => '18:00:00',
                'end_time' => '19:00:00',
                'capacity_override' => 2,
            ]);

            $startAt = Carbon::parse($date.' 18:00:00', 'UTC');
            $endAt = Carbon::parse($date.' 19:00:00', 'UTC');

            foreach (range(1, 2) as $_) {
                $this->reservation([
                    'amenity_id' => $amenity->id,
                    'unit_id' => $this->unit()->id,
                    'reservation_date' => $date,
                    'slot_id' => $slot->id,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                ]);
            }

            return ['amenity_id' => $amenity->id, 'slot_id' => $slot->id];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertOk();

        $entry = collect($response->json('data'))->firstWhere('amenity_id', $context['amenity_id']);

        $this->assertNotNull($entry);
        $this->assertSame($context['slot_id'], $entry['slot_id']);
        $this->assertSame(2, $entry['occupied']);
        $this->assertSame(2, $entry['capacity'], 'capacity_override on the slot must win over the amenity default.');
        $this->assertNotNull($entry['start_at']);
        $this->assertNotNull($entry['end_at']);
    }

    // =========================================================================
    // access / gating
    // =========================================================================

    public function test_a_resident_holding_read_amenity_reservation_can_call_the_endpoint(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        $date = now()->addDays(3)->toDateString();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertOk();
    }

    public function test_flag_off_blocks_the_occupancy_endpoint(): void
    {
        $tenant = $this->makeTenant('Amenity Occupancy Flag Off');
        $this->seedTenant($tenant);
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;
        $date = now()->addDays(3)->toDateString();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertStatus(403)
            ->assertJsonPath('code', 'AMENITY_RESERVATIONS_DISABLED');
    }

    // =========================================================================
    // bounded range
    // =========================================================================

    public function test_a_range_exceeding_the_cap_returns_a_422(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->oversightToken($tenant);

        $from = now()->addDay()->toDateString();
        $to = now()->addDays(70)->toDateString();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$from.'&to='.$to)
            ->assertStatus(422);
    }

    public function test_to_before_from_returns_a_422(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->oversightToken($tenant);

        $from = now()->addDays(5)->toDateString();
        $to = now()->addDays(1)->toDateString();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/occupancy?from='.$from.'&to='.$to)
            ->assertStatus(422);
    }

    // =========================================================================
    // tenant isolation
    // =========================================================================

    public function test_occupancy_does_not_leak_across_tenants(): void
    {
        $tenantA = $this->tenantWithFlagEnabled('Tenant A');
        $tenantB = $this->tenantWithFlagEnabled('Tenant B');
        $date = now()->addDays(3)->toDateString();

        $this->inTenant($tenantA, function () use ($date): void {
            $amenity = $this->byDayAmenity();
            $this->reservation([
                'amenity_id' => $amenity->id,
                'unit_id' => $this->unit()->id,
                'reservation_date' => $date,
            ]);
        });

        [, $tokenB] = $this->oversightToken($tenantB);

        $response = $this->tenantRequest($tokenB, $tenantB)
            ->getJson('/api/amenity_reservation/occupancy?from='.$date.'&to='.$date)
            ->assertOk();

        $this->assertCount(0, $response->json('data'), 'Tenant B must not see Tenant A occupancy.');
    }
}
