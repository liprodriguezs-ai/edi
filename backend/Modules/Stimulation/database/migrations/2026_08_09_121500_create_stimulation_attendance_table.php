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
     * Attendance fact of a child in a group on a session date, upserted by
     * natural key (REQ-SA-001). Deliberate departure from the house partial-
     * unique pattern (design D8): the natural key (stimulation_group_id,
     * child_id, session_date) uses a PLAIN unique index, NOT a partial one
     * filtered by deleted_at. The row's identity never changes and there is
     * no destroy endpoint, so a plain unique guarantees the key ALWAYS
     * resolves to exactly one physical row for the upsert to target — a
     * partial unique would let a trashed duplicate coexist and defeat the
     * idempotent-upsert contract.
     */
    public function up(): void
    {
        Schema::create('stimulation_attendance', function (Blueprint $table) {
            $table->comment('Attendance fact of a child in a group on a session date; upserted by natural key (D8: plain unique, no destroy endpoint)');
            BaseModel::columns($table, true);
            $table->uuid('stimulation_group_id')->index()->comment('FK to stimulation_group');
            $table->uuid('child_id')->index()->comment('FK to child (same tenant, validated; must be an active group member)');
            $table->date('session_date')->comment('Date of the group session');
            $table->boolean('attended')->default(false)->comment('Whether the child attended');
            $table->boolean('homework_done')->default(false)->comment('Whether the take-home task was done');
            $table->uuid('attendance_payment_status_id')->index()->comment('FK to central attendance_payment_status; defaults from the member row');

            $table->unique(['stimulation_group_id', 'child_id', 'session_date']);
            $table->index(['tenant_id', 'session_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stimulation_attendance');
    }
};
