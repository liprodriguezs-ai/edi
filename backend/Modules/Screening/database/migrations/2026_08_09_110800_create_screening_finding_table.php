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
     * screening_finding persists every trace finding the engine emitted
     * (design D7, REQ-SCO-009/REQ-SCR-006), for clinical explainability
     * without re-running the engine.
     */
    public function up(): void
    {
        Schema::create('screening_finding', function (Blueprint $table) {
            $table->comment('Clinical trace finding from the engine (closed vocabulary)');
            BaseModel::columns($table, true);
            $table->uuid('screening_id')->index()->comment('FK to screening');
            $table->uuid('finding_type_id')->index()->comment('FK to central finding_type');
            $table->jsonb('context')->nullable()->comment('Typed context array from the trace (ScoringFinding::$context)');
        });

        DB::statement(
            'CREATE INDEX screening_finding_screening_idx ON screening_finding (screening_id)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_finding_screening_idx');
        Schema::dropIfExists('screening_finding');
    }
};
