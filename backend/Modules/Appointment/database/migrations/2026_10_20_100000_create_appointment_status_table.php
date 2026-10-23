<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * CENTRAL FSM catalog for the appointment lifecycle. AppointmentLifecycleService
     * and the anti-overlap constraint branch on these fixed UUIDs, so it is central
     * and read-only for tenant roles, seeded once by CatalogSeeder.
     */
    public function up(): void
    {
        Schema::create('appointment_status', function (Blueprint $table) {
            $table->comment('Central FSM catalog for the appointment lifecycle (scheduled/confirmed/arrived/fulfilled/cancelled/no_show)');
            BaseModel::columns($table, false);
            $table->string('key')->comment("Machine-readable slug ('scheduled'/'confirmed'/'arrived'/'fulfilled'/'cancelled'/'no_show').");
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX appointment_status_key_unique ON appointment_status (key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointment_status_key_unique');
        Schema::dropIfExists('appointment_status');
    }
};
