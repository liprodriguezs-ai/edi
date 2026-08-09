<?php

namespace Modules\AmenityReservations\Tests\Unit\Seeders;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\AmenityReservations\Models\ReservationMode;
use Tests\TestCase;

/**
 * `reservation_mode` (by_day/by_slot) is a behavior-selector CENTRAL catalog
 * (no tenant_id, fixed UUID constants), mirroring
 * Modules\Collections\Models\AmountMode / Periodicity — the same pattern
 * applied for reservation_status in S1.
 */
class CatalogSeederReservationModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_mode_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('reservation_mode', 'tenant_id'),
            'reservation_mode is a CENTRAL catalog and must NOT carry tenant_id.'
        );
    }

    public function test_reservation_modes_exist_with_fixed_uuids(): void
    {
        $this->seed(CatalogSeeder::class);

        $expected = [
            ReservationMode::BY_DAY => 'Por día',
            ReservationMode::BY_SLOT => 'Por horario',
        ];

        foreach ($expected as $id => $name) {
            $mode = ReservationMode::find($id);
            $this->assertNotNull($mode, "ReservationMode {$id} ({$name}) must exist");
            $this->assertSame($name, $mode->name);
            $this->assertTrue((bool) $mode->is_active);
        }

        $this->assertDatabaseCount('reservation_mode', 2);
    }

    public function test_seeding_is_idempotent_when_run_twice(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertDatabaseCount('reservation_mode', 2);
    }
}
