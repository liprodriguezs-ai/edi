<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * DB-level backstop for batch activation idempotency (spec §8.4.2 — one charge
     * per unit per batch): concurrent activations that slip past the application
     * lock can never double-charge a unit. Partial: ad-hoc charges have a NULL
     * charge_batch_id and soft-deleted rows must not block regeneration.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_unit_unique ON "charge" '
            .'(charge_batch_id, unit_id) '
            .'WHERE deleted_at IS NULL AND charge_batch_id IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_batch_unit_unique');
    }
};
