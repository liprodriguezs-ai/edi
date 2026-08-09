<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 — Auth/Onboarding:
 * - Make email NULLABLE with a POSTGRES PARTIAL unique (null emails do not conflict).
 * - Make password NULLABLE (data-only residents have no credentials).
 * - Add auth_active boolean (only users with auth_active=true can log in).
 * - Add last_active_tenant_id uuid nullable FK → tenant (remembers the user's last organization).
 *
 * onboarding_completed_at was added in a previous migration — do NOT re-add it.
 *
 * NOTE: In PostgreSQL, `->unique()` creates a CONSTRAINT (not a bare index).
 * We must use ALTER TABLE … DROP CONSTRAINT before we can recreate it as a partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop the unique CONSTRAINT that was created by `->unique()` in the create migration.
        //    PostgreSQL won't let you DROP INDEX on a constraint-backed index; use DROP CONSTRAINT.
        DB::statement('ALTER TABLE "user" DROP CONSTRAINT IF EXISTS "user_email_unique"');

        // 2. Now make email and password nullable (no constraint in the way).
        Schema::table('user', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        // 3. Recreate as a PARTIAL unique: only non-null emails must be unique.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "user_email_unique" ON "user" (email) WHERE email IS NOT NULL');

        // 4. Add new columns.
        Schema::table('user', function (Blueprint $table) {
            $table->boolean('auth_active')->default(true)->after('password')->comment('When false, the user cannot log in even if credentials exist');
            $table->foreignUuid('last_active_tenant_id')
                ->nullable()
                ->after('auth_active')
                ->references('id')
                ->on('tenant')
                ->nullOnDelete()
                ->comment('FK to the tenant the user last interacted with (used for default organization selection)');
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['last_active_tenant_id']);
            $table->dropColumn(['auth_active', 'last_active_tenant_id']);
        });

        // Restore: drop partial index and make email NOT NULL with a plain unique constraint.
        DB::statement('DROP INDEX IF EXISTS "user_email_unique"');

        Schema::table('user', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
            $table->unique('email', 'user_email_unique');
        });
    }
};
