<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CENTRAL catalog (design D1/D12, REQ-SCR-001/012): the 7 situation
     * states mirrored 1:1 from the engine's SituationKey constants, plus the
     * legacy sis_code (1-6) used by the official export. `unchanged` and
     * `worsened` are distinct rows sharing sis_code 6.
     */
    public function up(): void
    {
        Schema::create('screening_situation', function (Blueprint $table) {
            $table->comment('Central catalog of situation states (engine SituationKey mirror) with the legacy sis_code export mapping');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment('Machine-readable slug, equal to Modules\\Screening\\Services\\Scoring\\Values\\SituationKey constants.');
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('sis_code')->comment('Legacy official-export code (1-6); unchanged and worsened both map to 6.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screening_situation');
    }
};
