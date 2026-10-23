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
     * Versioned content (D4): biological risk factor (FRB) of a version.
     * Three carry auto_derivation_key so the future engine can auto-derive
     * them from birth_record; this change stores the metadata only.
     */
    public function up(): void
    {
        Schema::create('risk_factor', function (Blueprint $table) {
            $table->comment('Biological risk factor (FRB) of a version; three are auto-derivable from birth_record');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version.');
            $table->string('code')->comment("'FRB1'...'FRB7'; unique per version (partial).");
            $table->text('question')->comment('Verbatim question text.');
            $table->smallInteger('sort_order')->comment('Presentation order.');
            $table->string('auto_derivation_key')->nullable()->comment("FRB3='gestational_age_lt_34_weeks', FRB4='birth_weight_lte_1500_g', FRB7='mother_age_lt_16_years'; NULL for the other four.");
            $table->integer('legacy_id')->nullable()->comment('Original id_edi_frb from the legacy dump, for traceability. Hidden from the API (REQ-INS-004).');
        });

        DB::statement(
            'CREATE UNIQUE INDEX risk_factor_version_code_unique ON risk_factor (instrument_version_id, code) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS risk_factor_version_code_unique');
        Schema::dropIfExists('risk_factor');
    }
};
