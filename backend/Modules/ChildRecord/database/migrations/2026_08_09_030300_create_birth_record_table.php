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
     * birth_record is a 1:1 perinatal record. Every clinical field is
     * NULLABLE and NULL means "se ignora" (unknown) — it MUST be
     * distinguishable from false/0 (D7/D-14): a risk factor is auto-derived
     * ONLY from a known value, never from a silently-defaulted one.
     */
    public function up(): void
    {
        Schema::create('birth_record', function (Blueprint $table) {
            $table->comment('Perinatal record of a child (1:1); feeds corrected age and auto-derived biological risk factors; NULL = unknown (se ignora)');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->index()->comment('FK to child; one active record per child');
            $table->smallInteger('gestational_age_weeks')->nullable()->comment('Gestational age in whole weeks (20-44); NULL = se ignora. <34 auto-derives a risk factor');
            $table->integer('birth_weight_grams')->nullable()->comment('Birth weight in grams, integer minor units (400-7000); NULL = se ignora. <=1500 auto-derives a risk factor');
            $table->integer('birth_length_mm')->nullable()->comment('Birth length in millimeters, integer minor units (200-650); NULL = se ignora');
            $table->smallInteger('prenatal_visit_count')->nullable()->comment('Number of prenatal visits (0-40); NULL = se ignora');
            $table->boolean('had_pregnancy_complication')->nullable()->comment('Pregnancy complication; NULL = se ignora (unknown, NOT false)');
            $table->boolean('had_delivery_complication')->nullable()->comment('Delivery complication; NULL = se ignora (unknown, NOT false)');
            $table->smallInteger('nicu_days')->nullable()->comment('Days in NICU / UCIN (0-365); NULL = se ignora');
            $table->smallInteger('mother_age_at_birth_years')->nullable()->comment("Mother's age at delivery in whole years (10-60); NULL = se ignora. <16 auto-derives a risk factor");
            $table->boolean('early_breastfeeding')->nullable()->comment('Breastfeeding initiated early; NULL = se ignora (unknown, NOT false)');
            $table->smallInteger('breastfeeding_months')->nullable()->comment('Total months breastfed (0-71); NULL = se ignora/ongoing-not-captured');
        });

        DB::statement(
            'CREATE UNIQUE INDEX birth_record_child_unique ON birth_record (child_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS birth_record_child_unique');
        Schema::dropIfExists('birth_record');
    }
};
