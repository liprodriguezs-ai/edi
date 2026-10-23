<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * CENTRAL WHO reference (S5), migrated from legacy cat_talla_edad and stored in
     * millimetres (hard rule 7).
     */
    public function up(): void
    {
        Schema::create('height_for_age_reference', function (Blueprint $table) {
            $table->comment('WHO height-for-age SD table, months 0-60');
            BaseModel::columns($table, false);
            $table->uuid('sex_id')->index()->comment('FK to central sex');
            $table->smallInteger('age_months')->comment('Completed months of age (0-60)');
            $table->integer('sd_minus3')->comment('Height at -3 SD, millimetres');
            $table->integer('sd_minus2')->comment('Height at -2 SD, millimetres');
            $table->integer('sd_minus1')->comment('Height at -1 SD, millimetres');
            $table->integer('median')->comment('Median height, millimetres');
            $table->integer('sd_plus1')->comment('Height at +1 SD, millimetres');
            $table->integer('sd_plus2')->comment('Height at +2 SD, millimetres');
            $table->integer('sd_plus3')->comment('Height at +3 SD, millimetres');
            $table->unique(['sex_id', 'age_months']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('height_for_age_reference');
    }
};
