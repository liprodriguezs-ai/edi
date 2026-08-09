<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert the legacy plain UNIQUE(tenant_id, plate) on `vehicle` into a partial
     * unique index scoped to live rows (WHERE deleted_at IS NULL), mirroring
     * unit_identity_unique. The original create_vehicle_table migration shipped a
     * plain unique constraint, which keeps reserving the plate of a soft-deleted
     * vehicle forever — re-registering that plate would fail with a 422 even though
     * no live vehicle holds it.
     *
     * Idempotent: editing the create migration does NOT re-run it on databases where
     * `vehicle` already exists, so this migration performs the conversion there. On a
     * fresh database (where the amended create migration already produced the partial
     * index) every statement is a guarded no-op.
     */
    public function up(): void
    {
        // Postgres backs $table->unique() with a table CONSTRAINT; SQLite backs it
        // with a unique index. Drop whichever form exists.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "vehicle" DROP CONSTRAINT IF EXISTS vehicle_tenant_id_plate_unique');
        }

        DB::statement('DROP INDEX IF EXISTS vehicle_tenant_id_plate_unique');

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS vehicle_plate_unique ON "vehicle" '
            .'(tenant_id, plate) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vehicle_plate_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "vehicle" ADD CONSTRAINT vehicle_tenant_id_plate_unique UNIQUE (tenant_id, plate)');
        } else {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS vehicle_tenant_id_plate_unique ON "vehicle" (tenant_id, plate)');
        }
    }
};
