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
     * TENANT-SCOPED admin-editable catalog: didactic materials suggested for
     * the free-text material columns of a stimulation session (D3 — the
     * session stores what was actually used as free text; this catalog only
     * feeds combobox suggestions). No default rows (admin fills, design §8 Q2).
     */
    public function up(): void
    {
        Schema::create('didactic_material', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of didactic materials, feeds combobox suggestions only (D3)');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Material name shown in the UI/suggestions.');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this material is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX didactic_material_tenant_name_unique ON didactic_material (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS didactic_material_tenant_name_unique');
        Schema::dropIfExists('didactic_material');
    }
};
