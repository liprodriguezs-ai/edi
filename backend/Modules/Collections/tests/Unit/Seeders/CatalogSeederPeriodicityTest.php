<?php

namespace Modules\Collections\Tests\Unit\Seeders;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\Periodicity;
use Tests\TestCase;

/**
 * Seeder test: verifies the periodicity rows the CatalogSeeder actually seeds, with their
 * is_automatic values.
 *
 * USER DECISION: every recurring periodicity is automatic (is_automatic=true); only
 * EVENTUAL is manual-only.
 *
 * OWNER DECISION (2026-07-26): BIMONTHLY, WEEKLY and BIWEEKLY stay commented out in
 * CatalogSeeder::seedPeriodicities(). Their model constants and existing DB rows survive
 * (the seeder stopped inserting them, it never deleted them), so only a FRESH database —
 * like this one — lacks them. The negative assertions below document that decision and
 * will fail loudly if someone re-enables a row without revisiting it.
 */
class CatalogSeederPeriodicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_monthly_is_automatic_true(): void
    {
        $p = Periodicity::find(Periodicity::MONTHLY);
        $this->assertNotNull($p, 'MONTHLY periodicity must exist');
        $this->assertTrue((bool) $p->is_automatic, 'MONTHLY must be is_automatic=true');
    }

    public function test_yearly_is_automatic_true(): void
    {
        $p = Periodicity::find(Periodicity::YEARLY);
        $this->assertNotNull($p, 'YEARLY periodicity must exist');
        // USER DECISION: yearly is automatic (overrides spec §1.2)
        $this->assertTrue((bool) $p->is_automatic, 'YEARLY must be is_automatic=true per user decision');
    }

    public function test_eventual_is_automatic_false(): void
    {
        $p = Periodicity::find(Periodicity::EVENTUAL);
        $this->assertNotNull($p, 'EVENTUAL periodicity must exist');
        $this->assertFalse((bool) $p->is_automatic, 'EVENTUAL must be is_automatic=false');
    }

    public function test_every_two_days_is_automatic_true(): void
    {
        $p = Periodicity::find(Periodicity::EVERY_TWO_DAYS);
        $this->assertNotNull($p, 'EVERY_TWO_DAYS periodicity must exist');
        $this->assertTrue((bool) $p->is_automatic, 'EVERY_TWO_DAYS must be is_automatic=true');
    }

    /**
     * Owner decision: these three rows are deliberately NOT seeded. If this test starts
     * failing, someone uncommented them in CatalogSeeder::seedPeriodicities() — confirm the
     * decision changed before "fixing" the assertion.
     */
    public function test_bimonthly_weekly_and_biweekly_are_not_seeded(): void
    {
        $this->assertNull(Periodicity::find(Periodicity::BIMONTHLY), 'BIMONTHLY must not be seeded');
        $this->assertNull(Periodicity::find(Periodicity::WEEKLY), 'WEEKLY must not be seeded');
        $this->assertNull(Periodicity::find(Periodicity::BIWEEKLY), 'BIWEEKLY must not be seeded');
    }

    public function test_exactly_four_periodicities_are_seeded(): void
    {
        $this->assertDatabaseCount('periodicity', 4);
    }
}
