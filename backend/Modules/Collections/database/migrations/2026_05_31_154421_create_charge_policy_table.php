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
     * charge_policy is the SINGLE point of charge configuration for a concept:
     * amount mode + amounts, grace, periodicity and validity window.
     * One concept can own several policies (1:N), but at most ONE active policy per
     * (concept, periodicity) — enforced by the partial unique index below.
     *
     * The collection MODE is intrinsic to the periodicity (no redundant column):
     *   - PERIODIC (periodicity.is_automatic = true): valid_from/valid_to define the
     *     active window; the cron generates batches automatically while [valid_from,
     *     valid_to] covers today.
     *   - DIRECT (periodicity.is_automatic = false, i.e. EVENTUAL): one-shot.
     *     valid_from/valid_to are NOT captured; a batch + its charges are generated
     *     IMMEDIATELY on policy creation. No cron involvement.
     */
    public function up(): void
    {
        Schema::create('charge_policy', function (Blueprint $table) {
            $table->comment('Single point of charge configuration for a concept: amount, grace, periodicity, mode, validity.');
            BaseModel::columns($table, true);
            $table->uuid('charge_concept_id')->comment('FK to charge_concept');
            $table->string('name')->comment('Label distinguishing several policies of the same concept.');
            $table->uuid('amount_mode_id')->comment('FK to amount_mode');
            $table->bigInteger('fixed_amount')->nullable()->comment('Minor units (cents). For fixed_required/fixed_optional.');
            $table->bigInteger('min_amount')->nullable()->comment('Minor units. For range mode minimum.');
            $table->bigInteger('ideal_amount')->nullable()->comment('Minor units. For range mode ideal (used as expected).');
            $table->bigInteger('max_amount')->nullable()->comment('Minor units. For range mode maximum.');
            $table->bigInteger('prorate_total_amount')->nullable()->comment('Minor units. For by_share mode total to prorate.');
            $table->smallInteger('grace_days')->nullable()->comment('Days after due_date before marking overdue.');
            $table->uuid('periodicity_id')->comment('FK to periodicity. Drives the collection mode: automatic periodicity = periodic (cron); EVENTUAL (non-automatic) = direct one-shot.');
            $table->date('valid_from')->nullable()->comment('Date from which the policy is effective (required for periodic; null for direct).');
            $table->date('valid_to')->nullable()->comment('Date until which the policy is effective (required for periodic; null for direct).');
            $table->boolean('is_active')->default(true)->comment('Fine switch: when false, this policy generates no charges.');

            $table->foreign('charge_concept_id')->references('id')->on('charge_concept');
            $table->foreign('amount_mode_id')->references('id')->on('amount_mode');
            $table->foreign('periodicity_id')->references('id')->on('periodicity');

            $table->index('charge_concept_id');
            $table->index('amount_mode_id');
            $table->index('periodicity_id');
        });

        // Anti-ambiguity (decision #1, rigid): at most ONE active policy per
        // (tenant, concept, periodicity) among alive rows.
        DB::statement(
            'CREATE UNIQUE INDEX charge_policy_active_unique ON "charge_policy" '
            .'(tenant_id, charge_concept_id, periodicity_id) '
            .'WHERE deleted_at IS NULL AND is_active = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_policy_active_unique');
        Schema::dropIfExists('charge_policy');
    }
};
