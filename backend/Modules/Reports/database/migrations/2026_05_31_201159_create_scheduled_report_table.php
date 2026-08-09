<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scheduled_report', function (Blueprint $table): void {
            BaseModel::columns($table, true);
            $table->string('report_key')->comment('Identifies which report to generate (maps to a registered report class)');
            $table->json('config')->nullable()->comment('JSON configuration passed to the report generator (filters, date range, etc.)');
            $table->string('frequency')->comment('Recurrence cadence: daily | weekly | monthly | quarterly | yearly');
            $table->string('format')->default('pdf')->comment('Output format: pdf | excel | json');
            $table->json('recipients')->nullable()->comment('JSON array of email addresses to receive the generated report');
            $table->timestamp('next_run_at')->nullable()->comment('When this scheduled report should next execute');
            $table->timestamp('last_run_at')->nullable()->comment('When this scheduled report last executed successfully');
            $table->boolean('is_active')->default(true)->comment('Whether this scheduled report is enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_report');
    }
};
