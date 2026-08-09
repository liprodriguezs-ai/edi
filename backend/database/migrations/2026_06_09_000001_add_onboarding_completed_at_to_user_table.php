<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds user.onboarding_completed_at (§5.3.5 — mobile welcome onboarding).
 *
 * The column was documented in domain.md and referenced by the comment in
 * 2026_05_31_062907_make_user_credentials_optional_and_add_auth_fields.php,
 * but never actually created (auth-08). It is the canonical EDI flag for
 * "the user saw the welcome slides"; the legacy CRM boolean `onboarding`
 * stays untouched (still written by the legacy uploadDocuments endpoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding')->comment('Timestamp when the user completed the EDI welcome onboarding slides; null means not yet completed');
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
