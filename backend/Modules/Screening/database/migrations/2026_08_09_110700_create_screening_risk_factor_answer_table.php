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
     * screening_risk_factor_answer stores one biological risk factor (FRB)
     * answer, manual or auto-derived from birth_record (design D8/D9/D12).
     */
    public function up(): void
    {
        Schema::create('screening_risk_factor_answer', function (Blueprint $table) {
            $table->comment('FRB answer (manual or auto-derived) of one screening');
            BaseModel::columns($table, true);
            $table->uuid('screening_id')->index()->comment('FK to screening');
            $table->uuid('risk_factor_id')->index()->comment('FK to risk_factor');
            $table->boolean('is_present')->comment('Whether the risk factor is present (yes/no); unknown factors are never stored');
            $table->boolean('is_auto_derived')->default(false)->comment('True when this answer was derived from birth_record instead of a manual answer');
        });

        DB::statement(
            'CREATE UNIQUE INDEX screening_risk_factor_answer_unique ON screening_risk_factor_answer (screening_id, risk_factor_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_risk_factor_answer_unique');
        Schema::dropIfExists('screening_risk_factor_answer');
    }
};
