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
     * Enrollment of a child in a stimulation group (REQ-SG-002). The pair
     * (stimulation_group_id, child_id) is unique among non-deleted rows.
     */
    public function up(): void
    {
        Schema::create('stimulation_group_member', function (Blueprint $table) {
            $table->comment('Enrollment of a child in a stimulation group');
            BaseModel::columns($table, true);
            $table->uuid('stimulation_group_id')->index()->comment('FK to stimulation_group');
            $table->uuid('child_id')->index()->comment('FK to child (same tenant, validated)');
            $table->date('enrolled_at')->comment('Enrollment date; defaults server-side to today when omitted');
            $table->uuid('default_attendance_payment_status_id')->index()->comment('FK to central attendance_payment_status; prefills the attendance sheet');
        });

        DB::statement(
            'CREATE UNIQUE INDEX stimulation_group_member_unique ON stimulation_group_member '
            .'(stimulation_group_id, child_id) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stimulation_group_member_unique');
        Schema::dropIfExists('stimulation_group_member');
    }
};
