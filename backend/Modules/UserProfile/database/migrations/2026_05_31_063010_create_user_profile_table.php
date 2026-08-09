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
     * user_profile is tenant-scoped PII (one row per user per cluster).
     * We do NOT FK-constrain user_id to the central `user` table to avoid
     * cross-connection FK issues in single-DB mode. The application-level
     * unique(['tenant_id','user_id']) enforces the one-row-per-user-per-tenant rule.
     */
    public function up(): void
    {
        Schema::create('user_profile', function (Blueprint $table) {
            BaseModel::columns($table, true); // adds id, timestamps, soft-delete, audit cols, tenant_id
            $table->uuid('user_id')->index()->comment('References user.id (no FK to avoid cross-connection issues in single-DB mode)');
            $table->string('rfc', 13)->nullable()->comment('Mexican tax ID (RFC) of the resident; up to 13 characters');
            $table->string('curp', 18)->nullable()->comment('Mexican CURP unique population registry code; 18 characters');
            $table->date('birthdate')->nullable()->comment('Date of birth of the resident');
            $table->text('address')->nullable()->comment('Personal address of the resident within or outside the cluster');
            $table->string('emergency_contact_name')->nullable()->comment('Name of the person to contact in an emergency');
            $table->string('emergency_contact_phone', 30)->nullable()->comment('Phone number of the emergency contact');
            $table->text('notes')->nullable()->comment('Internal notes about this resident profile (admin only)');
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profile');
    }
};
