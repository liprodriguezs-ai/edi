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
     * CENTRAL catalog (design D1, REQ-SCR-001/007): mirrors the engine's
     * closed ScoringFinding vocabulary 1:1, so every persisted
     * screening_finding row resolves to a stable UUID.
     */
    public function up(): void
    {
        Schema::create('finding_type', function (Blueprint $table) {
            $table->comment('Central catalog mirroring the closed engine finding vocabulary (ScoringFinding)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment('Machine-readable slug, equal to Modules\\Screening\\Services\\Scoring\\Values\\ScoringFinding constants.');
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_type');
    }
};
