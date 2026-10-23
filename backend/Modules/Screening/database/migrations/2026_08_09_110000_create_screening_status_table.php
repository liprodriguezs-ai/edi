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
     * CENTRAL FSM catalog (design D1/D15, REQ-SCR-001): the screening
     * lifecycle status. Code branches on these fixed UUIDs
     * (ScreeningLifecycleService), so it is central and read-only for tenant
     * roles, seeded once by CatalogSeeder.
     */
    public function up(): void
    {
        Schema::create('screening_status', function (Blueprint $table) {
            $table->comment('Central FSM catalog for the screening lifecycle (draft/scored/completed/invalidated)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('draft'/'scored'/'completed'/'invalidated').");
            $table->string('name')->comment('Human-readable name shown in the UI (Borrador/Calificado/Completado/Invalidado).');
            $table->smallInteger('sort_order')->comment('Display order.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screening_status');
    }
};
