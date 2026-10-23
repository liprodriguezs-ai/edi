<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every primary key must be a UUIDv7, whatever path creates the row: Eloquent
 * (HasUuids), explicit Str calls, or the column default used by raw inserts
 * and pivot tables. v7 keeps index inserts ordered and ids sortable by time.
 */
final class UuidV7DefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_function_generates_version_7_uuids(): void
    {
        $uuid = DB::selectOne('SELECT uuid_generate_v7()::text AS id')->id;

        $this->assertTrue(Str::isUuid($uuid));
        $this->assertSame('7', $uuid[14], "Expected version 7, got {$uuid}");
        $this->assertContains($uuid[19], ['8', '9', 'a', 'b'], "Expected RFC 4122 variant, got {$uuid}");
    }

    public function test_no_uuid_column_defaults_to_a_version_4_generator(): void
    {
        $v4Defaults = DB::select(
            "SELECT table_name, column_name, column_default
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND data_type = 'uuid'
                AND (column_default ILIKE '%uuid_generate_v4%' OR column_default ILIKE '%gen_random_uuid%')"
        );

        $this->assertSame([], array_map(
            fn (object $c): string => "{$c->table_name}.{$c->column_name} = {$c->column_default}",
            $v4Defaults,
        ));
    }

    public function test_tables_built_with_base_model_columns_default_to_v7(): void
    {
        $default = DB::selectOne(
            "SELECT column_default FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'tenant' AND column_name = 'id'"
        )->column_default;

        $this->assertStringContainsString('uuid_generate_v7', (string) $default);
    }
}
