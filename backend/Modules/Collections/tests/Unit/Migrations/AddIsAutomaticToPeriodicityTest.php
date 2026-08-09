<?php

namespace Modules\Collections\Tests\Unit\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration smoke-test: verifies is_automatic column exists after migration runs.
 * [RED] Written before migration; will be green once migration is created.
 */
class AddIsAutomaticToPeriodicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_periodicity_table_has_is_automatic_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('periodicity', 'is_automatic'),
            'periodicity.is_automatic column must exist after migration'
        );
    }

    public function test_is_automatic_column_is_not_nullable(): void
    {
        $columns = \DB::select("
            SELECT column_name, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_name = 'periodicity'
              AND column_name = 'is_automatic'
        ");

        $this->assertCount(1, $columns, 'is_automatic column must exist');
        $col = $columns[0];
        $this->assertEquals('NO', $col->is_nullable, 'is_automatic must be NOT NULL');
        $this->assertNotNull($col->column_default, 'is_automatic must have a default');
    }

    public function test_is_automatic_default_is_false(): void
    {
        $columns = \DB::select("
            SELECT column_default
            FROM information_schema.columns
            WHERE table_name = 'periodicity'
              AND column_name = 'is_automatic'
        ");

        $this->assertCount(1, $columns);
        // PostgreSQL stores boolean default as 'false' or 'true'
        $this->assertStringContainsString('false', strtolower($columns[0]->column_default));
    }
}
