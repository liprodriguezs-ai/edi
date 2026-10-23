<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Central because the scheduler BRANCHES on the row: `daily`/`weekly`/`monthly`
     * decide whether a report is due tonight (hard rule 8 — code branches on it,
     * so it is central with fixed UUIDs, not a tenant-editable label).
     */
    public function up(): void
    {
        Schema::create('report_frequency', function (Blueprint $table) {
            $table->comment('Central catalog of scheduled-report cadences the runner branches on (daily/weekly/monthly)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable cadence the runner branches on ('daily'/'weekly'/'monthly').");
            $table->string('name')->comment('Human-readable name shown in the UI (Diario/Semanal/Mensual).');
            $table->smallInteger('sort_order')->comment('Display order.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_frequency');
    }
};
