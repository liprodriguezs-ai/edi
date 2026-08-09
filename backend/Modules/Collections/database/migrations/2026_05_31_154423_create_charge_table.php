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
        Schema::create('charge', function (Blueprint $table) {
            $table->comment('Individual fee charged to a unit, generated from a charge batch.');
            BaseModel::columns($table, true);
            $table->uuid('charge_batch_id')->nullable()->comment('Null for ad-hoc one-off charges.');
            $table->uuid('charge_concept_id')->comment('FK to charge_concept');
            $table->uuid('charge_policy_id')->nullable()->comment('FK to charge_policy (traceability: which policy produced this charge).');
            $table->uuid('unit_id')->comment('FK to unit');
            $table->bigInteger('expected_amount')->comment('Minor units (cents). Immutable after first payment applied.');
            $table->bigInteger('paid_amount')->default(0)->comment('Minor units. Denormalized cache = sum(payment_application.applied_amount).');
            $table->date('issued_at')->comment('Date the charge was issued and became visible to the resident.');
            $table->date('due_date')->comment('Payment due date for this charge.');
            $table->smallInteger('grace_days')->nullable()->comment('Snapshot from concept at generation time.');
            $table->uuid('charge_status_id')->comment('Stored, maintained by ChargeStatusService on every change.');
            $table->text('cancellation_reason')->nullable()->comment('Reason provided when the charge was cancelled.');
            $table->boolean('is_cancelled')->default(false)->comment('True when the charge has been voided by an admin.');

            $table->foreign('charge_batch_id')->references('id')->on('charge_batch')->nullOnDelete();
            $table->foreign('charge_concept_id')->references('id')->on('charge_concept');
            $table->foreign('charge_policy_id')->references('id')->on('charge_policy')->nullOnDelete();
            $table->foreign('charge_status_id')->references('id')->on('charge_status');
            // A charge always belongs to a real unit; restrict hard-deletes of a unit
            // that still owns charges (units are soft-deleted in practice, so this is a backstop).
            $table->foreign('unit_id')->references('id')->on('unit')->restrictOnDelete();

            $table->index('charge_status_id');
            $table->index('unit_id');
            $table->index(['unit_id', 'is_cancelled', 'due_date']);
            $table->index('charge_concept_id');
            $table->index('charge_policy_id');
            // Drives the daily status-transition cron (per-tenant scan by status + due_date).
            $table->index(['tenant_id', 'charge_status_id', 'due_date'], 'charge_tenant_status_due_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge');
    }
};
