<?php

namespace Modules\Collections\Tests\Unit\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration smoke-test: verifies cycle column + new partial unique index on charge_batch.
 * [RED] Written before migration.
 */
class AddCycleToChargeBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_charge_batch_has_cycle_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('charge_batch', 'cycle'),
            'charge_batch.cycle column must exist after migration'
        );
    }

    public function test_cycle_column_is_nullable_smallint(): void
    {
        $columns = \DB::select("
            SELECT column_name, is_nullable, data_type, udt_name
            FROM information_schema.columns
            WHERE table_name = 'charge_batch'
              AND column_name = 'cycle'
        ");

        $this->assertCount(1, $columns);
        $col = $columns[0];
        $this->assertEquals('YES', $col->is_nullable, 'cycle must be nullable');
        // Postgres reports smallint as 'int2' in udt_name; both represent smallint.
        $this->assertContains($col->udt_name, ['smallint', 'int2'], 'cycle must be smallint (int2 in Postgres)');
    }

    public function test_old_monthly_unique_index_is_gone(): void
    {
        $indexes = \DB::select("
            SELECT indexname
            FROM pg_indexes
            WHERE tablename = 'charge_batch'
              AND indexname = 'charge_batch_monthly_unique'
        ");

        $this->assertEmpty($indexes, 'charge_batch_monthly_unique index must be removed');
    }

    /**
     * ANCHORED BILLING PERIODS: the COALESCE-cycle index was itself superseded by
     * charge_batch_period_start_unique (period_start-based idempotency). Both the old
     * COALESCE index AND the intermediate cycle-based one must be gone.
     */
    public function test_coalesce_cycle_period_unique_index_is_gone(): void
    {
        $indexes = \DB::select("
            SELECT indexname
            FROM pg_indexes
            WHERE tablename = 'charge_batch'
              AND indexname = 'charge_batch_period_unique'
        ");

        $this->assertEmpty($indexes, 'charge_batch_period_unique (COALESCE-cycle) index must be removed');
    }

    public function test_charge_batch_has_period_start_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('charge_batch', 'period_start'),
            'charge_batch.period_start column must exist after migration'
        );
    }

    public function test_period_start_unique_index_exists_and_is_partial(): void
    {
        $indexes = \DB::select("
            SELECT indexdef
            FROM pg_indexes
            WHERE tablename = 'charge_batch'
              AND indexname = 'charge_batch_period_start_unique'
        ");

        $this->assertCount(1, $indexes, 'charge_batch_period_start_unique index must exist');

        $indexDef = strtolower($indexes[0]->indexdef);
        $this->assertStringContainsString('period_start', $indexDef);
        $this->assertStringContainsString('deleted_at is null', $indexDef);
        $this->assertStringContainsString('period_start is not null', $indexDef);
    }
}
