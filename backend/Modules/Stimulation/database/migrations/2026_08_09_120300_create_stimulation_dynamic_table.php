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
     * TENANT-SCOPED admin-editable catalog: dynamics used in the integration
     * and core sections of a stimulation session (Mamá/bebé, Bebé/bebé, Grupal,
     * Circuito), no branching logic.
     */
    public function up(): void
    {
        Schema::create('stimulation_dynamic', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of stimulation dynamics used in the integration/core sections');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Dynamic name shown in the UI (e.g. Mamá/bebé, Grupal).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this dynamic is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX stimulation_dynamic_tenant_name_unique ON stimulation_dynamic (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stimulation_dynamic_tenant_name_unique');
        Schema::dropIfExists('stimulation_dynamic');
    }
};
