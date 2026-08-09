<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a PARTIAL unique index on user.phone (§5.4 r2).
 *
 * A user's phone, when present, must be unique. Following the same pattern used for
 * user.email, this is a Postgres PARTIAL unique index that only constrains rows with
 * a non-null phone AND that are not soft-deleted — so multiple data-only residents
 * with a null phone can coexist, and a soft-deleted user frees its phone for reuse.
 *
 * NOTE: this only adds the uniqueness guarantee. There is NO OTP/phone login flow —
 * that remains deferred per the product decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "user_phone_unique" ON "user" (phone) '
            .'WHERE phone IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "user_phone_unique"');
    }
};
