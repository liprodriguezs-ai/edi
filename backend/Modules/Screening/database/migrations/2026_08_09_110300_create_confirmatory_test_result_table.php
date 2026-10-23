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
     * CENTRAL catalog (design D1/D20, REQ-SCR-014): the possible outcomes of
     * a confirmatory test (e.g. Battelle) applied after a red result.
     */
    public function up(): void
    {
        Schema::create('confirmatory_test_result', function (Blueprint $table) {
            $table->comment('Central catalog of confirmatory test outcomes (delay confirmed/ruled out/inconclusive)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('delay_confirmed'/'delay_ruled_out'/'inconclusive').");
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confirmatory_test_result');
    }
};
