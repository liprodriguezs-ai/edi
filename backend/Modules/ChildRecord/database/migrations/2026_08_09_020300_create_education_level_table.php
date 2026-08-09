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
     * TENANT-SCOPED admin-editable catalog (D5): caregiver education-level
     * labels, no branching logic.
     */
    public function up(): void
    {
        Schema::create('education_level', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of caregiver education-level labels');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Education level label shown in the UI (e.g. Primaria, Secundaria).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this label is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX education_level_tenant_name_unique ON education_level (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS education_level_tenant_name_unique');
        Schema::dropIfExists('education_level');
    }
};
