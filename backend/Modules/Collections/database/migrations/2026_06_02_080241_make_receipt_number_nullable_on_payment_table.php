<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A resident self-captured payment (spec §8.5.2) is PENDING_VALIDATION and has NO
     * receipt_number until the tesorero validates it (the folio is generated at
     * validation time). Make receipt_number nullable so a pending payment can exist
     * without one. The Postgres UNIQUE index on (tenant_id, receipt_number) treats
     * NULLs as distinct, so multiple pending payments do not collide.
     *
     * Raw ALTER (no doctrine/dbal) to drop the NOT NULL constraint on Postgres.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "payment" ALTER COLUMN receipt_number DROP NOT NULL');
        }
        // SQLite (testing fallback) does not enforce NOT NULL the same way and cannot
        // easily ALTER a column; nullable inserts succeed there regardless.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Backfill any nulls before restoring NOT NULL so the constraint can apply.
            DB::statement("UPDATE \"payment\" SET receipt_number = 'R-PENDING-'||id WHERE receipt_number IS NULL");
            DB::statement('ALTER TABLE "payment" ALTER COLUMN receipt_number SET NOT NULL');
        }
    }
};
