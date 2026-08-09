<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the partial unique index on user.phone.
 *
 * Phone uniqueness blocked bulk/data imports (and real residents legitimately
 * share a phone — e.g. a household landline or a billing contact). Phone stays
 * a plain, non-unique column. Reverses 2026_06_02_102304_add_phone_unique_to_user_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS "user_phone_unique"');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "user_phone_unique" ON "user" (phone) '
            .'WHERE phone IS NOT NULL AND deleted_at IS NULL'
        );
    }
};
