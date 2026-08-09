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
     * TENANT-SCOPED admin-editable catalog (D5): kinship labels, no branching
     * logic. Seeded per tenant by TenantCatalogSeeder on TenantCreated.
     */
    public function up(): void
    {
        Schema::create('caregiver_relationship', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of caregiver/child kinship labels (madre, padre, abuela, ...)');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Kinship label shown in the UI (e.g. Madre, Padre, Tutor legal).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this label is currently offered when linking a caregiver.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX caregiver_relationship_tenant_name_unique ON caregiver_relationship (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS caregiver_relationship_tenant_name_unique');
        Schema::dropIfExists('caregiver_relationship');
    }
};
