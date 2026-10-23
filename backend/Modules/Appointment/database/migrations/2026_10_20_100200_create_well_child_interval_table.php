<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * CENTRAL data table of well-child recall intervals by age band (NOM-031-SSA2-1999).
     * The next-appointment suggestion reads it instead of hardcoding the bands,
     * same principle as the scoring thresholds living in data.
     */
    public function up(): void
    {
        Schema::create('well_child_interval', function (Blueprint $table) {
            $table->comment('Central recall interval for well-child visits by age band (NOM-031-SSA2-1999)');
            BaseModel::columns($table, false);
            $table->integer('min_age_days')->comment('Inclusive lower bound of the age band, in days of chronological age.');
            $table->integer('max_age_days')->nullable()->comment('Inclusive upper bound of the age band, in days; NULL = no upper bound.');
            $table->integer('interval_days')->comment('Days between consecutive well-child visits for this band.');
            $table->string('source')->comment('Normative reference for the interval (shown as the suggestion reason).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('well_child_interval');
    }
};
