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
     * TENANT-SCOPED admin-editable catalog: types of stimulation group (e.g.
     * Grupo abierto), no branching logic. Seeded with one default row so
     * group creation is never blocked by an empty required catalog (design §8
     * Q4).
     */
    public function up(): void
    {
        Schema::create('stimulation_group_type', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of stimulation group types');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Group type name shown in the UI (e.g. Grupo abierto).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this group type is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX stimulation_group_type_tenant_name_unique ON stimulation_group_type (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stimulation_group_type_tenant_name_unique');
        Schema::dropIfExists('stimulation_group_type');
    }
};
