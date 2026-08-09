<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S5/S6 groundwork — proves the `amenity_reservation_by_day_unique` PARTIAL
 * UNIQUE INDEX (tenant_id, amenity_id, reservation_date) WHERE holds_slot
 * AND slot_id IS NULL AND deleted_at IS NULL is a real DATABASE constraint,
 * not app-level validation: rows are inserted with raw DB::table() to bypass
 * Eloquent entirely.
 */
final class AmenityReservationDoubleBookingIndexTest extends TestCase
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

    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
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

    /**
     * @return array<string, mixed>
     */
    private function rawReservationRow(string $tenantId, string $amenityId, string $unitId, string $date, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'amenity_id' => $amenityId,
            'unit_id' => $unitId,
            'user_id' => (string) Str::uuid(),
            'reservation_date' => $date,
            'slot_id' => null,
            'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
            'holds_slot' => true,
            'amount_outstanding' => 0,
            'created_at' => now(),
        ], $overrides);
    }

    public function test_a_second_holding_by_day_reservation_for_the_same_amenity_and_date_is_rejected_by_the_database(): void
    {
        $tenant = $this->makeTenant('Amenity Double Booking');
        $this->seedTenant($tenant);

        $tenant->run(function () use ($tenant): void {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $date = now()->addDay()->toDateString();

            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenity->id, $unit->id, $date)
            );

            $this->expectException(QueryException::class);

            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenity->id, $unit->id, $date)
            );
        });
    }

    public function test_a_released_reservation_does_not_block_a_new_holding_reservation_for_the_same_day(): void
    {
        $tenant = $this->makeTenant('Amenity Double Booking Released');
        $this->seedTenant($tenant);

        $tenant->run(function () use ($tenant): void {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $date = now()->addDay()->toDateString();

            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenity->id, $unit->id, $date, [
                    'holds_slot' => false,
                    'reservation_status_id' => ReservationStatus::CANCELLED,
                ])
            );

            // Must NOT throw: the first row has holds_slot=false, so it is
            // excluded by the partial index's WHERE clause.
            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenity->id, $unit->id, $date)
            );

            $this->assertSame(
                2,
                DB::table('amenity_reservation')->where('amenity_id', $amenity->id)->count()
            );
        });
    }

    public function test_by_slot_reservations_are_not_limited_to_one_per_amenity_per_day(): void
    {
        $tenant = $this->makeTenant('Amenity Double Booking BySlot');
        $this->seedTenant($tenant);

        $tenant->run(function () use ($tenant): void {
            $amenity = $this->amenity(['reservation_mode_id' => ReservationMode::BY_SLOT]);
            $unit = $this->unit();
            $tomorrow = now()->addDay();
            $date = $tomorrow->toDateString();

            $slot = AmenitySlot::create([
                'amenity_id' => $amenity->id,
                'weekday' => (int) $tomorrow->dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'capacity_override' => 5,
            ]);

            // Two holding by_slot reservations (slot_id set) for the SAME
            // amenity+date must NOT collide with the by_day partial index,
            // which is scoped to `slot_id IS NULL`.
            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenity->id, $unit->id, $date, ['slot_id' => $slot->id])
            );

            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenity->id, $unit->id, $date, ['slot_id' => $slot->id])
            );

            $this->assertSame(
                2,
                DB::table('amenity_reservation')->where('amenity_id', $amenity->id)->count()
            );
        });
    }

    public function test_the_same_date_is_allowed_across_different_amenities(): void
    {
        $tenant = $this->makeTenant('Amenity Double Booking Cross Amenity');
        $this->seedTenant($tenant);

        $tenant->run(function () use ($tenant): void {
            $amenityA = $this->amenity(['name' => 'Amenidad A '.uniqid()]);
            $amenityB = $this->amenity(['name' => 'Amenidad B '.uniqid()]);
            $unit = $this->unit();
            $date = now()->addDay()->toDateString();

            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenityA->id, $unit->id, $date)
            );

            // Different amenity, same date — must NOT collide.
            DB::table('amenity_reservation')->insert(
                $this->rawReservationRow($tenant->id, $amenityB->id, $unit->id, $date)
            );

            $this->assertSame(1, DB::table('amenity_reservation')->where('amenity_id', $amenityA->id)->count());
            $this->assertSame(1, DB::table('amenity_reservation')->where('amenity_id', $amenityB->id)->count());
        });
    }
}
