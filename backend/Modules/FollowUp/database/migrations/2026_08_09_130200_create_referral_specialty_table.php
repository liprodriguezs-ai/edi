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
     * TENANT-SCOPED admin-editable catalog (design D6/REQ-FUP-001): pure
     * classification, no branching logic. Seeded per tenant by
     * TenantCatalogSeeder on TenantCreated.
     */
    public function up(): void
    {
        Schema::create('referral_specialty', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of specialist referral specialties (neurología pediátrica, audiología, ...)');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Specialty label shown in the UI (e.g. Neurología pediátrica, Audiología).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this specialty is currently offered when creating a referral.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX referral_specialty_tenant_name_unique ON referral_specialty (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS referral_specialty_tenant_name_unique');
        Schema::dropIfExists('referral_specialty');
    }
};
