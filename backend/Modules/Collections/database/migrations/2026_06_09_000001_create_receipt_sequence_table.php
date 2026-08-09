<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-tenant per-year receipt folio counter (R-YYYY-NNNNNN, spec §8.7.1.3).
     * Incremented under a row lock so concurrent payment registrations can never
     * collide on the same folio (no MAX/COUNT scan, scales with payment volume).
     */
    public function up(): void
    {
        Schema::create('receipt_sequence', function (Blueprint $table) {
            $table->comment('Per-tenant per-year counter for generating sequential receipt numbers (R-YYYY-NNNNNN).');
            BaseModel::columns($table, true);
            $table->smallInteger('year')->comment('Calendar year this sequence counter belongs to.');
            $table->bigInteger('last_number')->default(0)->comment('Last issued NNNNNN for this tenant/year.');
        });

        // One ALIVE counter per tenant/year: partial so a soft-deleted row can be replaced
        // without colliding (mirrors the module's other partial unique indexes).
        DB::statement(
            'CREATE UNIQUE INDEX receipt_sequence_tenant_year_unique ON "receipt_sequence" '
            .'(tenant_id, year) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS receipt_sequence_tenant_year_unique');
        Schema::dropIfExists('receipt_sequence');
    }
};
