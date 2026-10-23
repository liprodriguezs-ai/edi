<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switches every UUID column still defaulting to a v4 generator
 * (uuid_generate_v4() or gen_random_uuid()) to uuid_generate_v7().
 *
 * Only the column DEFAULT changes; ids already stored stay as they are.
 * Older migrations still declare v4 defaults, so this sweep must remain the
 * last word on them for both existing and freshly migrated databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->switchDefaults("(column_default ILIKE '%uuid_generate_v4%' OR column_default ILIKE '%gen_random_uuid%')", 'uuid_generate_v7()');
    }

    public function down(): void
    {
        $this->switchDefaults("column_default ILIKE '%uuid_generate_v7%'", 'gen_random_uuid()');
    }

    private function switchDefaults(string $matchingDefault, string $newDefault): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::select(
            "SELECT table_name, column_name
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND data_type = 'uuid'
                AND {$matchingDefault}"
        );

        foreach ($columns as $column) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" ALTER COLUMN "%s" SET DEFAULT %s',
                $column->table_name,
                $column->column_name,
                $newDefault,
            ));
        }
    }
};
