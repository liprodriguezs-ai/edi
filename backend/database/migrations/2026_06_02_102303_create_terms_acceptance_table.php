<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * terms_acceptance (CENTRAL — no tenant_id):
 *
 * Records a user's acceptance of a given Terms & Conditions version. T&C acceptance
 * is a platform-level legal record keyed by (user, version): the central `user`
 * table is shared across all tenants, so this table is central too. A unique index
 * on (user_id, version) makes re-accepting the SAME version idempotent, while a new
 * version inserts a fresh row.
 *
 * user_id is a bare uuid column (no FK), matching the codebase's audit-column pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_acceptance', function (Blueprint $table) {
            BaseModel::columns($table, false);
            $table->uuid('user_id')->comment('FK to user (bare UUID, no FK constraint — matches audit-column pattern)');
            $table->string('version')->comment('Terms & Conditions version the user accepted (e.g., "1.0", "2024-01")');
            $table->timestamp('accepted_at')->comment('Timestamp when the user accepted this T&C version');
            $table->string('ip')->nullable()->comment('Client IP address at the time of acceptance');

            $table->unique(['user_id', 'version']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptance');
    }
};
