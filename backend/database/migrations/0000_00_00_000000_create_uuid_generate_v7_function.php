<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates uuid_generate_v7(), the default of every UUID primary key.
 *
 * It runs before any other migration because BaseModel::columns() references it.
 * PostgreSQL 18 ships uuidv7() natively; older servers (the supported floor is 16)
 * get a pure-SQL RFC 9562 implementation: the 48-bit Unix millisecond timestamp
 * overlaid on a random v4, with the version nibble switched from 4 to 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $serverVersion = (int) DB::selectOne('SHOW server_version_num')->server_version_num;

        $body = $serverVersion >= 180000
            ? 'SELECT uuidv7()'
            : "SELECT encode(
                   set_bit(
                       set_bit(
                           overlay(uuid_send(gen_random_uuid())
                               placing substring(int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3)
                               FROM 1 FOR 6),
                           52, 1),
                       53, 1),
                   'hex')::uuid";

        DB::statement("CREATE OR REPLACE FUNCTION uuid_generate_v7() RETURNS uuid LANGUAGE sql VOLATILE AS \$\$ {$body} \$\$");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS uuid_generate_v7()');
    }
};
