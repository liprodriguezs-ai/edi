<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * CENTRAL WHO reference (S5), migrated from legacy cat_peso_talla and
     * stored in grams/millimetres (hard rule 7).
     */
    public function up(): void
    {
        Schema::create('weight_for_height_reference', function (Blueprint $table) {
            $table->comment('WHO weight-for-length (0-23 months) and weight-for-height (24-60 months) SD table');
            BaseModel::columns($table, false);
            $table->uuid('sex_id')->index()->comment('FK to central sex');
            $table->string('band', 20)->comment("Age band: 'under_24_months' (length table) or '24_to_60_months' (height table).");
            $table->smallInteger('height_mm')->comment('Length/height in millimetres, in 5 mm (0.5 cm) steps');
            $table->integer('sd_minus3')->comment('Weight at -3 SD, grams');
            $table->integer('sd_minus2')->comment('Weight at -2 SD, grams');
            $table->integer('sd_minus1')->comment('Weight at -1 SD, grams');
            $table->integer('median')->comment('Median weight, grams');
            $table->integer('sd_plus1')->comment('Weight at +1 SD, grams');
            $table->integer('sd_plus2')->comment('Weight at +2 SD, grams');
            $table->integer('sd_plus3')->comment('Weight at +3 SD, grams');
            $table->unique(['sex_id', 'band', 'height_mm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_for_height_reference');
    }
};
