<?php

namespace Modules\AmenityReservations\Tests\Unit\Seeders;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\AmenityReservations\Models\ReservationStatus;
use Tests\TestCase;

/**
 * Seeder test — reservation_status is a CENTRAL catalog (no tenant_id, fixed
 * UUID constants), mirroring Modules\Collections\Tests for charge_status.
 */
class CatalogSeederReservationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_status_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('reservation_status', 'tenant_id'),
            'reservation_status is a CENTRAL catalog and must NOT carry tenant_id.'
        );
    }

    public function test_all_seven_statuses_exist_with_fixed_uuids(): void
    {
        $this->seed(CatalogSeeder::class);

        $expected = [
            ReservationStatus::PENDING_APPROVAL => 'Pendiente de aprobación',
            ReservationStatus::PENDING_PAYMENT => 'Pendiente de pago',
            ReservationStatus::CONFIRMED => 'Confirmada',
            ReservationStatus::CANCELLED => 'Cancelada',
            ReservationStatus::REJECTED => 'Rechazada',
            ReservationStatus::COMPLETED => 'Completada',
            ReservationStatus::NO_SHOW => 'No presentado',
        ];

        foreach ($expected as $id => $name) {
            $status = ReservationStatus::find($id);
            $this->assertNotNull($status, "ReservationStatus {$id} ({$name}) must exist");
            $this->assertSame($name, $status->name);
            $this->assertTrue((bool) $status->is_active);
        }

        $this->assertDatabaseCount('reservation_status', 7);
    }

    public function test_seeding_is_idempotent_when_run_twice(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertDatabaseCount('reservation_status', 7);
    }
}
