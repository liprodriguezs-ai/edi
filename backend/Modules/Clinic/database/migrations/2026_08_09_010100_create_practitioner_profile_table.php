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
     * practitioner_profile is the professional identity of a user who applies
     * screenings in this tenant. One profile per user per tenant (D10),
     * enforced by a partial unique index so a soft-deleted profile frees the
     * user for a new one.
     */
    public function up(): void
    {
        Schema::create('practitioner_profile', function (Blueprint $table) {
            $table->comment('Professional identity of a user who applies screenings in this tenant');
            BaseModel::columns($table, true);
            $table->uuid('user_id')->index()->comment('References user.id (no FK per D2); must be a member of the tenant');
            $table->string('license_number', 20)->comment('Professional license (cédula profesional)');
            $table->string('specialty')->comment('Specialty or discipline (e.g. pediatría, enfermería, psicología)');
            $table->uuid('health_center_id')->index()->comment('FK to health_center (primary assignment; no DB FK per D2)');
        });

        DB::statement(
            'CREATE UNIQUE INDEX practitioner_profile_tenant_user_unique ON practitioner_profile (tenant_id, user_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS practitioner_profile_tenant_user_unique');
        Schema::dropIfExists('practitioner_profile');
    }
};
