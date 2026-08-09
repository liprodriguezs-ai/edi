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
     * TENANT-SCOPED admin-editable catalog (D5): caregiver occupation labels,
     * no branching logic.
     */
    public function up(): void
    {
        Schema::create('occupation', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of caregiver occupation labels');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Occupation label shown in the UI (e.g. Hogar, Comerciante).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this label is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX occupation_tenant_name_unique ON occupation (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS occupation_tenant_name_unique');
        Schema::dropIfExists('occupation');
    }
};
