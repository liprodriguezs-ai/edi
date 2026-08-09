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
     * health_center is tenant-scoped structure (no DB FK per Clinic/ChildRecord
     * design D2 — cross-module references stay as indexed UUID columns validated
     * at the application level).
     */
    public function up(): void
    {
        Schema::create('health_center', function (Blueprint $table) {
            $table->comment('Health center (unidad médica) belonging to the tenant organization');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Official name of the health center');
            $table->string('clues_code', 11)->nullable()->comment('CLUES code (Clave Única de Establecimientos de Salud); uppercase, 5 letters + 6 digits');
            $table->uuid('municipality_id')->index()->comment('References municipality.id (central catalog; no DB FK per D2)');
            $table->string('address')->comment('Street address of the health center');
            $table->boolean('is_active')->default(true)->comment('Whether the center currently operates and can receive visits');
        });

        DB::statement(
            'CREATE UNIQUE INDEX health_center_tenant_clues_unique ON health_center (tenant_id, clues_code) '
            .'WHERE clues_code IS NOT NULL AND deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS health_center_tenant_clues_unique');
        Schema::dropIfExists('health_center');
    }
};
