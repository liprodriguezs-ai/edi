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
     * CENTRAL FSM catalog (design D6/D7, REQ-FUP-001/005): FollowUpLifecycleService
     * and the overdue-convergence command branch on these fixed UUIDs, so it
     * is central and read-only for tenant roles, seeded once by CatalogSeeder.
     */
    public function up(): void
    {
        Schema::create('follow_up_status', function (Blueprint $table) {
            $table->comment('Central FSM catalog for the follow-up lifecycle (pending/in_progress/completed/overdue/cancelled)');
            BaseModel::columns($table, false);
            $table->string('key')->comment("Machine-readable slug ('pending'/'in_progress'/'completed'/'overdue'/'cancelled').");
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX follow_up_status_key_unique ON follow_up_status (key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS follow_up_status_key_unique');
        Schema::dropIfExists('follow_up_status');
    }
};
