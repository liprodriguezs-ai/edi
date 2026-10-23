<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_report', function (Blueprint $table) {
            $table->comment('A saved report the nightly runner regenerates on a cadence and delivers in-app to one recipient');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Label the recipient sees in the notification (Spanish).');
            $table->string('report_model_key')->comment('Key of the mapped report model to generate (REPORT_MODEL_MAP: Screening/Child/ScreeningIndicator).');
            $table->jsonb('params')->nullable()->comment('Saved filter/sort query params replayed on each run; null = unfiltered.');
            $table->uuid('report_frequency_id')->comment('FK to report_frequency — the cadence the runner branches on.');
            $table->uuid('recipient_user_id')->comment('FK to user who receives the in-app notification with the download link.');
            $table->boolean('is_active')->default(true)->comment('Paused schedules stay stored but are skipped by the runner.');
            // The idempotency anchor: the runner compares this against today to
            // guarantee one delivery per period even if the command runs twice.
            $table->timestamp('last_run_at')->nullable()->comment('When the runner last generated and delivered this report; null = never run.');

            $table->foreign('report_frequency_id')->references('id')->on('report_frequency');
            $table->foreign('recipient_user_id')->references('id')->on('user');
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_report');
    }
};
