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
     */
    public function up(): void
    {
        Schema::create('charge_batch', function (Blueprint $table) {
            $table->comment('Batch of charges generated from a charge_policy for a set of units in a billing period.');
            BaseModel::columns($table, true);
            $table->uuid('charge_policy_id')->comment('FK to charge_policy');
            $table->string('title')->comment('Human-readable batch title for the admin UI.');
            $table->text('description')->nullable()->comment('Optional notes about this batch.');
            $table->smallInteger('year')->comment('Billing year this batch belongs to.');
            $table->smallInteger('month')->nullable()->comment('Null for non-monthly (eventual) batches.');
            $table->string('target_type')->comment('all|by_type|by_tower|by_floor|manual|by_feature|by_share|single_unit');
            $table->json('target_selector')->nullable()->comment('Selector payload matching target_type.');
            $table->date('issued_at')->comment('Date the batch was issued and charges became visible.');
            $table->date('due_date')->comment('Payment due date applied to all charges in this batch.');
            $table->uuid('charge_batch_status_id')->comment('FK to charge_batch_status');
            $table->smallInteger('progress_pct')->default(0)->comment('Stored, recomputed when charges change.');
            $table->boolean('is_initial')->default(false)->comment('Marks the original/template batch the periodic job clones each period; at most one per charge_policy.');

            $table->foreign('charge_policy_id')->references('id')->on('charge_policy');
            $table->foreign('charge_batch_status_id')->references('id')->on('charge_batch_status');

            $table->index('charge_policy_id');
            $table->index('charge_batch_status_id');
        });

        // Idempotency guard: prevent cron from double-creating a monthly batch.
        // Partial unique on (tenant_id, charge_policy_id, year, month) for monthly batches only.
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_monthly_unique ON "charge_batch" '
            .'(tenant_id, charge_policy_id, year, month) '
            .'WHERE deleted_at IS NULL AND month IS NOT NULL'
        );

        // Template guard: at most ONE initial/template batch per policy (the one the
        // periodic cron clones). Partial unique so it ignores soft-deleted rows and the
        // many non-initial period copies (is_initial = false).
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_initial_unique ON "charge_batch" '
            .'(charge_policy_id) '
            .'WHERE deleted_at IS NULL AND is_initial = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_batch_initial_unique');
        DB::statement('DROP INDEX IF EXISTS charge_batch_monthly_unique');
        Schema::dropIfExists('charge_batch');
    }
};
