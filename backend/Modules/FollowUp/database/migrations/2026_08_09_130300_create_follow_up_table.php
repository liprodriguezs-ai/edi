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
     * follow_up is the conduct derived from a screening result: reassessment,
     * specialist referral, or confirmatory-test tracking (design §3, D1-D7).
     * Every FSM/frozen field is written exclusively by services (REQ-FUP-006):
     * $fillable on the model excludes follow_up_status_id, closed_by_screening_id,
     * completed_at.
     */
    public function up(): void
    {
        Schema::create('follow_up', function (Blueprint $table) {
            $table->comment('Conduct derived from a screening result: reassessment, referral, or confirmatory-test tracking');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->index()->comment('FK to child (denormalized for the inbox and child history)');
            $table->uuid('screening_id')->index()->comment('FK to the screening whose result mandated this conduct');
            $table->uuid('follow_up_type_id')->index()->comment('FK to central follow_up_type');
            $table->uuid('follow_up_status_id')->index()->comment('FK to central follow_up_status; service-derived, never client-writable');
            $table->date('due_date')->comment('Date the conduct must happen by; drives the derived overdue state');
            $table->uuid('referral_specialty_id')->nullable()->comment('FK to tenant referral_specialty; suggested specialty for referral follow-ups');
            $table->uuid('closed_by_screening_id')->nullable()->index()->comment('FK to the later screening that auto-closed this reassessment; NULL = manual close');
            $table->timestamp('completed_at')->nullable()->comment('Moment the follow-up reached completed (auto or manual)');
            $table->text('cancellation_reason')->nullable()->comment('Mandatory reason captured on cancel');
            $table->text('notes')->nullable()->comment('Clinical/administrative notes');
        });

        DB::statement(
            'CREATE INDEX follow_up_tenant_status_due_idx ON follow_up (tenant_id, follow_up_status_id, due_date)'
        );

        DB::statement(
            'CREATE INDEX follow_up_tenant_child_idx ON follow_up (tenant_id, child_id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX follow_up_screening_type_unique ON follow_up (screening_id, follow_up_type_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS follow_up_tenant_status_due_idx');
        DB::statement('DROP INDEX IF EXISTS follow_up_tenant_child_idx');
        DB::statement('DROP INDEX IF EXISTS follow_up_screening_type_unique');
        Schema::dropIfExists('follow_up');
    }
};
