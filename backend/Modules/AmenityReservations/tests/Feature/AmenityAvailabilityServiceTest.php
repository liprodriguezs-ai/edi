<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityBlackout;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Services\AmenityAvailabilityService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S5 — AmenityAvailabilityService: structural rules (out_of_service,
 * available_weekdays, amenity_blackout) combined with occupancy (existing
 * amenity_reservation rows with holds_slot=true). All date math must resolve
 * "today" from the TENANT's timezone, never bare UTC.
 */
final class AmenityAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeMexicanTenant(string $name): Tenant
    {
        $tenant = $this->makeTenant($name);
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function () use ($tenant): void {
            $tenant->update(['timezone' => 'America/Mexico_City']);
        });

        return $tenant;
    }

    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [1, 2, 3, 4, 5], // Monday..Friday
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ], $overrides));
    }

    private function unit(): Unit
    {
        return Unit::factory()->create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            // Force a unique number: UnitFactory's number is optional()->numerify('###')
            // (~50% null), so multiple units per tenant collide on unit_identity_unique
            // (all-NULL identity tuple) ~25% of the time — pre-existing flakiness.
            'number' => (string) random_int(100000, 999999),
        ]);
    }

    /** A future Wednesday — guaranteed inside [1..5] (Monday..Friday). */
    private function futureOpenWeekday(): Carbon
    {
        return Carbon::now()->addWeek()->next(Carbon::WEDNESDAY);
    }

    /** A future Sunday — guaranteed outside [1..5] (Monday..Friday). */
    private function futureClosedWeekday(): Carbon
    {
        return Carbon::now()->addWeek()->next(Carbon::SUNDAY);
    }

    // =========================================================================
    // Structural rules
    // =========================================================================

    public function test_closed_weekday_is_not_available(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability Weekday');

        $tenant->run(function (): void {
            $amenity = $this->amenity();
            $date = $this->futureClosedWeekday();

            $result = app(AmenityAvailabilityService::class)->forDate($amenity, $date->toDateString());

            $this->assertFalse($result['days'][0]['open']);
            $this->assertSame('closed_weekday', $result['days'][0]['reason']);
            $this->assertFalse($result['days'][0]['available']);
        });
    }

    public function test_blackout_date_is_not_available(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability Blackout');

        $tenant->run(function (): void {
            $amenity = $this->amenity();
            $date = $this->futureOpenWeekday();

            AmenityBlackout::create([
                'amenity_id' => $amenity->id,
                'start_date' => $date->toDateString(),
                'end_date' => $date->toDateString(),
                'reason' => 'Mantenimiento',
            ]);

            $result = app(AmenityAvailabilityService::class)->forDate($amenity, $date->toDateString());

            $this->assertFalse($result['days'][0]['open']);
            $this->assertSame('blackout', $result['days'][0]['reason']);
            $this->assertFalse($result['days'][0]['available']);
        });
    }

    public function test_out_of_service_amenity_is_not_available(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability OOS');

        $tenant->run(function (): void {
            $amenity = $this->amenity(['out_of_service' => true, 'out_of_service_reason' => 'Remodelación']);
            $date = $this->futureOpenWeekday();

            $result = app(AmenityAvailabilityService::class)->forDate($amenity, $date->toDateString());

            $this->assertFalse($result['days'][0]['open']);
            $this->assertSame('out_of_service', $result['days'][0]['reason']);
            $this->assertFalse($result['days'][0]['available']);
        });
    }

    // =========================================================================
    // by_day occupancy
    // =========================================================================

    public function test_by_day_amenity_is_available_until_a_holding_reservation_exists(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability ByDay');

        $tenant->run(function (): void {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $date = $this->futureOpenWeekday();

            $before = app(AmenityAvailabilityService::class)->forDate($amenity, $date->toDateString());
            $this->assertTrue($before['days'][0]['available']);

            AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $date->toDateString(),
                'holds_slot' => true,
            ]);

            $after = app(AmenityAvailabilityService::class)->forDate($amenity, $date->toDateString());
            $this->assertFalse($after['days'][0]['available']);
        });
    }

    public function test_released_by_day_reservation_does_not_block_the_date(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability Released');

        $tenant->run(function (): void {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $date = $this->futureOpenWeekday();

            AmenityReservation::factory()->released()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $date->toDateString(),
            ]);

            $result = app(AmenityAvailabilityService::class)->forDate($amenity, $date->toDateString());
            $this->assertTrue($result['days'][0]['available'], 'A cancelled/rejected (holds_slot=false) reservation must not block re-booking.');
        });
    }

    // =========================================================================
    // by_slot occupancy / capacity
    // =========================================================================

    public function test_by_slot_amenity_reports_correct_free_capacity_as_reservations_are_added(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability BySlot');

        $tenant->run(function (): void {
            $amenity = $this->amenity(['reservation_mode_id' => ReservationMode::BY_SLOT, 'capacity' => 10]);
            $unit = $this->unit();
            $date = $this->futureOpenWeekday();

            $slot = AmenitySlot::create([
                'amenity_id' => $amenity->id,
                'weekday' => (int) $date->dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'capacity_override' => 2,
            ]);

            $service = app(AmenityAvailabilityService::class);

            $result0 = $service->forDate($amenity, $date->toDateString());
            $this->assertSame(0, $result0['days'][0]['slots'][0]['taken']);
            $this->assertSame(2, $result0['days'][0]['slots'][0]['free']);
            $this->assertTrue($result0['days'][0]['available']);
            $this->assertNotNull($result0['days'][0]['slots'][0]['start_at']);

            AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'slot_id' => $slot->id,
                'reservation_date' => $date->toDateString(),
                'holds_slot' => true,
            ]);

            $result1 = $service->forDate($amenity, $date->toDateString());
            $this->assertSame(1, $result1['days'][0]['slots'][0]['taken']);
            $this->assertSame(1, $result1['days'][0]['slots'][0]['free']);
            $this->assertTrue($result1['days'][0]['available']);

            AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'slot_id' => $slot->id,
                'reservation_date' => $date->toDateString(),
                'holds_slot' => true,
            ]);

            $result2 = $service->forDate($amenity, $date->toDateString());
            $this->assertSame(2, $result2['days'][0]['slots'][0]['taken']);
            $this->assertSame(0, $result2['days'][0]['slots'][0]['free']);
            $this->assertFalse($result2['days'][0]['available'], 'No slot has free capacity left.');
        });
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_reservations_from_another_tenant_never_affect_availability(): void
    {
        $tenantA = $this->makeMexicanTenant('Amenity Availability Tenant A');
        $tenantB = $this->makeMexicanTenant('Amenity Availability Tenant B');
        $date = $this->futureOpenWeekday();

        $amenityAId = $tenantA->run(function () use ($date) {
            $amenity = $this->amenity();
            $unit = $this->unit();

            AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $date->toDateString(),
                'holds_slot' => true,
            ]);

            return $amenity->id;
        });

        $tenantB->run(function () use ($date): void {
            $amenityB = $this->amenity();

            $result = app(AmenityAvailabilityService::class)->forDate($amenityB, $date->toDateString());
            $this->assertTrue($result['days'][0]['available'], 'Tenant B availability must not be affected by Tenant A reservations.');
        });

        $this->assertNotNull($amenityAId);
    }

    // =========================================================================
    // Occupancy batching (single query per range, not per open day)
    // =========================================================================

    public function test_for_range_occupancy_is_fetched_via_a_single_query_regardless_of_range_length(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability Batch Queries');

        $tenant->run(function (): void {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $start = $this->futureOpenWeekday();

            AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $start->toDateString(),
                'holds_slot' => true,
            ]);

            $service = app(AmenityAvailabilityService::class);

            // Re-fetch a fresh model per measurement so loadMissing(['slots','blackouts'])
            // is not "free" on the second call from relation caching on the same instance.
            $shortRangeAmenity = Amenity::findOrFail($amenity->id);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->forRange($shortRangeAmenity, $start->toDateString(), $start->copy()->addDays(2)->toDateString());
            $shortRangeQueryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $longRangeAmenity = Amenity::findOrFail($amenity->id);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->forRange($longRangeAmenity, $start->toDateString(), $start->copy()->addDays(20)->toDateString());
            $longRangeQueryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame(
                $shortRangeQueryCount,
                $longRangeQueryCount,
                'Occupancy must be fetched via ONE batched query for the whole range, not one query per open day (a 21-day range must not fire more queries than a 3-day range).'
            );
        });
    }

    // =========================================================================
    // Timezone correctness
    // =========================================================================

    public function test_today_boundary_resolves_from_tenant_timezone_not_utc(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability Timezone');

        $tenant->run(function (): void {
            $amenity = $this->amenity();

            // America/Mexico_City is UTC-6 year-round. Freeze the app clock
            // (UTC) at 2026-07-07 03:00 UTC == 2026-07-06 21:00 Mexico City.
            // A bare UTC "today" would read 2026-07-07 and wrongly reject
            // 2026-07-06 as a past date; the tenant-timezone "today" is
            // correctly still 2026-07-06.
            Carbon::setTestNow(Carbon::parse('2026-07-07 03:00:00', 'UTC'));

            $localToday = '2026-07-06';
            $weekday = (int) Carbon::parse($localToday, 'America/Mexico_City')->dayOfWeek;
            $amenity->update(['available_weekdays' => range(0, 6)]);
            $this->assertSame($weekday, (int) Carbon::parse($localToday, 'America/Mexico_City')->dayOfWeek);

            $result = app(AmenityAvailabilityService::class)->forDate($amenity, $localToday);

            $this->assertTrue($result['days'][0]['available'], 'Same local-tenant-day request must not be rejected as a past date.');
        });
    }

    public function test_a_truly_past_local_date_is_rejected(): void
    {
        $tenant = $this->makeMexicanTenant('Amenity Availability Past Date');

        $tenant->run(function (): void {
            $amenity = $this->amenity();

            Carbon::setTestNow(Carbon::parse('2026-07-07 03:00:00', 'UTC')); // 2026-07-06 21:00 Mexico City

            $this->expectException(\InvalidArgumentException::class);

            app(AmenityAvailabilityService::class)->forDate($amenity, '2026-07-05');
        });
    }
}
