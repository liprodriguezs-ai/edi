<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Prueba EDI a elección" (odd/tasks/edi-legacy-parity.md S2): the evaluator
     * may pick the applied group instead of the age-derived one. A two-value
     * mode flag validated against Screening::SELECTION_MODES -- not a status the
     * FSM branches over, so no catalog table.
     */
    public function up(): void
    {
        Schema::table('screening', function (Blueprint $table) {
            $table->string('age_group_selection_mode', 10)
                ->default('age')
                ->after('applied_age_group_id')
                ->comment("How applied_age_group_id was selected: 'age' (resolved from the child's age, incl. corrected-age substitution) or 'chosen' (picked by the evaluator, Prueba EDI a elección)");
        });
    }

    public function down(): void
    {
        Schema::table('screening', function (Blueprint $table) {
            $table->dropColumn('age_group_selection_mode');
        });
    }
};
