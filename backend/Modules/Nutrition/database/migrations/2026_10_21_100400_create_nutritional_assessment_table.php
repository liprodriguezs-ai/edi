<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * TENANT table (S5): one somatometry measurement of a child with its
     * server-computed nutritional status. Either captured on its own from the
     * child record, or synced from a Prueba EDI (screening_id set).
     */
    public function up(): void
    {
        Schema::create('nutritional_assessment', function (Blueprint $table) {
            $table->comment('Child somatometry measurement with server-computed WHO nutritional status');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->index()->comment('FK to child');
            $table->uuid('screening_id')->nullable()->comment('FK to the screening (Prueba EDI) this measurement was synced from; NULL = captured on its own');
            $table->date('measured_on')->comment('Civil date of the measurement (visit date when synced from a screening)');
            $table->smallInteger('age_months')->comment('Completed months between birth_date and measured_on; server-computed');
            $table->integer('weight_grams')->nullable()->comment('Weight in grams (minor units)');
            $table->smallInteger('height_mm')->nullable()->comment('Length/height in millimetres');
            $table->uuid('weight_for_height_status_id')->nullable()->index()->comment('FK to nutritional_status for P/T; server-computed, NULL only when a measure is missing');
            $table->uuid('weight_for_age_status_id')->nullable()->index()->comment('FK to nutritional_status for P/E; server-computed, NULL only when weight is missing');
            $table->uuid('height_for_age_status_id')->nullable()->index()->comment('FK to nutritional_status for T/E; server-computed, NULL only when height is missing');
            $table->text('notes')->nullable()->comment('Clinical notes of the measurement');
        });

        DB::statement(
            'CREATE UNIQUE INDEX nutritional_assessment_screening_unique ON nutritional_assessment (screening_id) '
            .'WHERE deleted_at IS NULL AND screening_id IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX nutritional_assessment_tenant_child_date_idx ON nutritional_assessment (tenant_id, child_id, measured_on DESC)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nutritional_assessment_screening_unique');
        DB::statement('DROP INDEX IF EXISTS nutritional_assessment_tenant_child_date_idx');
        Schema::dropIfExists('nutritional_assessment');
    }
};
