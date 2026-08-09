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
     * Per-tenant per-year folio counter (D-YYYY-NNNNNN). Incremented under a row
     * lock so concurrent complaint registrations can never collide on the same
     * folio (no MAX/COUNT scan, mirrors Collections' receipt_sequence).
     */
    public function up(): void
    {
        Schema::create('complaint_sequence', function (Blueprint $table) {
            $table->comment('Per-tenant per-year counter for generating sequential complaint folios (D-YYYY-NNNNNN).');
            BaseModel::columns($table, true);
            $table->smallInteger('year')->comment('Calendar year this sequence counter belongs to.');
            $table->bigInteger('last_number')->default(0)->comment('Last issued NNNNNN for this tenant/year.');
        });

        // One ALIVE counter per tenant/year: partial so a soft-deleted row can be replaced
        // without colliding (mirrors receipt_sequence's partial unique index).
        DB::statement(
            'CREATE UNIQUE INDEX complaint_sequence_tenant_year_unique ON "complaint_sequence" '
            .'(tenant_id, year) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS complaint_sequence_tenant_year_unique');
        Schema::dropIfExists('complaint_sequence');
    }
};
