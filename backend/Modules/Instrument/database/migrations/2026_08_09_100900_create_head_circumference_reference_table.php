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
     * Versioned content (D4): percentile band (+-2 SD) of head circumference
     * in integer millimeters (source cm x 10, exact -- hard rule 7 forbids
     * decimal/float for a comparison the engine treats as clinical data),
     * keyed by (sex, age in months). References ChildRecord's central sex
     * catalog app-level (D19), no DB FK.
     */
    public function up(): void
    {
        Schema::create('head_circumference_reference', function (Blueprint $table) {
            $table->comment('Percentile band (+-2 SD) of head circumference in integer mm by sex and age in months');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version.');
            $table->uuid('sex_id')->index()->comment('References the central sex catalog (ChildRecord module), app-level, no DB FK (D19).');
            $table->smallInteger('age_months')->comment('Age in whole months (1-59 seeded; G15 window 60-71 has no reference).');
            $table->smallInteger('min_circumference_mm')->comment('Lower percentile bound, integer millimeters (source cm x 10, exact).');
            $table->smallInteger('max_circumference_mm')->comment('Upper percentile bound, integer millimeters (source cm x 10, exact).');
            $table->integer('legacy_id')->nullable()->comment('Original id_edi_perimetro_cefalico from the legacy dump, for traceability. Hidden from the API (REQ-INS-004).');
        });

        DB::statement(
            'CREATE UNIQUE INDEX hc_reference_version_sex_month_unique ON head_circumference_reference (instrument_version_id, sex_id, age_months) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS hc_reference_version_sex_month_unique');
        Schema::dropIfExists('head_circumference_reference');
    }
};
