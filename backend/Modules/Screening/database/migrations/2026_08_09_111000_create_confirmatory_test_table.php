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
     * confirmatory_test records a follow-up test (e.g. Battelle) applied
     * after a red completed result (design D20, REQ-SCR-014). At most one
     * per screening.
     */
    public function up(): void
    {
        Schema::create('confirmatory_test', function (Blueprint $table) {
            $table->comment('Confirmatory test (e.g. Battelle) after a red result');
            BaseModel::columns($table, true);
            $table->uuid('screening_id')->index()->comment('FK to the red, completed screening it follows up on');
            $table->uuid('child_id')->index()->comment('FK to child (denormalized from screening)');
            $table->string('test_name')->comment("Name of the applied test, e.g. 'Battelle'");
            $table->date('applied_at')->nullable()->comment('Date the test was applied (not in the future); NULL until known');
            $table->uuid('confirmatory_test_result_id')->nullable()->index()->comment('FK to central confirmatory_test_result; NULL until known');
            $table->text('notes')->nullable()->comment('Clinical notes about the confirmatory test');
        });

        DB::statement(
            'CREATE UNIQUE INDEX confirmatory_test_screening_unique ON confirmatory_test (screening_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS confirmatory_test_screening_unique');
        Schema::dropIfExists('confirmatory_test');
    }
};
