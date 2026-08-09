<?php

namespace Modules\Collections\Tests\Unit\Services;

use Carbon\Carbon;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\PeriodicityScheduleResolver;
use Tests\TestCase;

/**
 * Unit tests for PeriodicityScheduleResolver::periodFor() — ANCHORED BILLING PERIODS.
 * Pure unit test — no DB, no tenant. Carbon dates are passed explicitly.
 *
 * The legacy calendar-grid API (runsForToday, firstPeriodFor, and their describe/resolve
 * helpers) was deleted once the cutover completed — periodFor() is now the only production
 * code path, and since 2026-07-25 it is ONE formula for all six automatic cadences:
 * end = (start + interval) − 1 day. The pre-anchored grid shapes (BIWEEKLY calendar halves,
 * EVERY_TWO_DAYS epoch windows) are gone because both could open a period BEFORE the
 * policy's valid_from.
 */
class PeriodicityScheduleResolverTest extends TestCase
{
    private PeriodicityScheduleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PeriodicityScheduleResolver;
    }

    // =========================================================================
    // periodFor() — ANCHORED BILLING PERIODS
    //
    // periodFor() returns the period that STARTS at the given periodStart — the
    // engine behind chained generation.
    // =========================================================================

    public function test_period_for_monthly_interval(): void
    {
        $run = $this->resolver->periodFor(Periodicity::MONTHLY, Carbon::create(2026, 6, 15), 5);

        $this->assertEquals('2026-06-15', $run->periodStart->toDateString());
        $this->assertEquals('2026-07-14', $run->dueDate->toDateString());
        $this->assertNull($run->cycle);
    }

    public function test_period_for_bimonthly_interval(): void
    {
        $run = $this->resolver->periodFor(Periodicity::BIMONTHLY, Carbon::create(2026, 6, 15), 5);

        $this->assertEquals('2026-06-15', $run->periodStart->toDateString());
        $this->assertEquals('2026-08-14', $run->dueDate->toDateString());
    }

    public function test_period_for_yearly_interval(): void
    {
        $run = $this->resolver->periodFor(Periodicity::YEARLY, Carbon::create(2026, 6, 15), 5);

        $this->assertEquals('2026-06-15', $run->periodStart->toDateString());
        $this->assertEquals('2027-06-14', $run->dueDate->toDateString());
    }

    public function test_period_for_weekly_interval(): void
    {
        $run = $this->resolver->periodFor(Periodicity::WEEKLY, Carbon::create(2026, 6, 15), 1);

        $this->assertEquals('2026-06-15', $run->periodStart->toDateString());
        $this->assertEquals('2026-06-21', $run->dueDate->toDateString());
    }

    // --- Overflow-safe interval math (spec: "Anchor on January 31st") ---

    public function test_period_for_monthly_overflow_jan31_non_leap(): void
    {
        $run = $this->resolver->periodFor(Periodicity::MONTHLY, Carbon::create(2026, 1, 31), 1);

        $this->assertEquals('2026-02-27', $run->dueDate->toDateString());
    }

    public function test_period_for_monthly_overflow_jan31_leap_year(): void
    {
        $run = $this->resolver->periodFor(Periodicity::MONTHLY, Carbon::create(2024, 1, 31), 1);

        $this->assertEquals('2024-02-28', $run->dueDate->toDateString());
    }

    public function test_period_for_bimonthly_overflow_dec31_across_year(): void
    {
        $run = $this->resolver->periodFor(Periodicity::BIMONTHLY, Carbon::create(2026, 12, 31), 1);
        $this->assertEquals('2027-02-27', $run->dueDate->toDateString());

        $next = $this->resolver->periodFor(Periodicity::BIMONTHLY, $run->dueDate->copy()->addDay(), 1);
        $this->assertEquals('2027-02-28', $next->periodStart->toDateString());
        $this->assertEquals('2027-04-27', $next->dueDate->toDateString());
    }

    public function test_period_for_yearly_overflow_leap_anchor_drifts_and_stabilizes(): void
    {
        $run = $this->resolver->periodFor(Periodicity::YEARLY, Carbon::create(2024, 2, 29), 1);
        $this->assertEquals('2025-02-27', $run->dueDate->toDateString());

        $next = $this->resolver->periodFor(Periodicity::YEARLY, $run->dueDate->copy()->addDay(), 1);
        $this->assertEquals('2025-02-28', $next->periodStart->toDateString());
        $this->assertEquals('2026-02-27', $next->dueDate->toDateString());

        // Drift stabilizes: the anchor stays Feb 28 forever, even into a future leap year.
        $future = $this->resolver->periodFor(Periodicity::YEARLY, Carbon::create(2027, 2, 28), 1);
        $this->assertEquals(
            '2028-02-27',
            $future->dueDate->toDateString(),
            'Anchor stays Feb 28 and never regains Feb 29, even though 2028 is a leap year',
        );
    }

    // --- Chain invariant: start(n+1) == end(n) + 1 day ---

    public function test_chain_invariant_holds_across_interval_periodicities(): void
    {
        $periodicityIds = [
            Periodicity::MONTHLY,
            Periodicity::BIMONTHLY,
            Periodicity::YEARLY,
            Periodicity::WEEKLY,
            Periodicity::BIWEEKLY,
            Periodicity::EVERY_TWO_DAYS,
        ];

        foreach ($periodicityIds as $periodicityId) {
            $cursor = Carbon::create(2026, 1, 15);

            for ($i = 0; $i < 5; $i++) {
                $run = $this->resolver->periodFor($periodicityId, $cursor, 5);
                $expectedNextStart = $run->dueDate->copy()->addDay();
                $next = $this->resolver->periodFor($periodicityId, $expectedNextStart, 5);

                $this->assertTrue(
                    $expectedNextStart->isSameDay($next->periodStart),
                    "Chain invariant violated for periodicity {$periodicityId} at iteration {$i}",
                );

                $cursor = $expectedNextStart;
            }
        }
    }

    // --- generationDay clamp behavior ---

    public function test_period_for_generation_day_clamps_above_28(): void
    {
        $run = $this->resolver->periodFor(Periodicity::MONTHLY, Carbon::create(2026, 2, 1), 31);

        $this->assertEquals('2026-02-28', $run->issuedAt->toDateString());
    }

    public function test_period_for_generation_day_clamps_to_period_end_when_shorter(): void
    {
        // WEEKLY period is only 7 days; genDay=28 clamps well past the end, so issuedAt
        // clamps down to the period's own end.
        $run = $this->resolver->periodFor(Periodicity::WEEKLY, Carbon::create(2026, 6, 15), 28);

        $this->assertEquals($run->dueDate->toDateString(), $run->issuedAt->toDateString());
    }

    // --- BIWEEKLY = 15-day interval anchored on the start (no calendar halves) ---

    public function test_period_for_biweekly_is_a_fifteen_day_interval_from_the_anchor(): void
    {
        // An anchor mid-half used to be pulled back to day 16 of the month. It must now
        // start exactly where it was asked to.
        $run = $this->resolver->periodFor(Periodicity::BIWEEKLY, Carbon::create(2026, 6, 20), 5);

        $this->assertEquals('2026-06-20', $run->periodStart->toDateString());
        $this->assertEquals('2026-07-04', $run->dueDate->toDateString(), 'End = start + 15 days − 1');
        $this->assertEquals(20, $run->cycle, 'cycle = start day of the anchored period');
    }

    public function test_period_for_biweekly_chains_contiguously(): void
    {
        $first = $this->resolver->periodFor(Periodicity::BIWEEKLY, Carbon::create(2026, 6, 1), 5);
        $this->assertEquals('2026-06-01', $first->periodStart->toDateString());
        $this->assertEquals('2026-06-15', $first->dueDate->toDateString());

        $second = $this->resolver->periodFor(Periodicity::BIWEEKLY, $first->dueDate->copy()->addDay(), 5);
        $this->assertEquals('2026-06-16', $second->periodStart->toDateString());
        $this->assertEquals('2026-06-30', $second->dueDate->toDateString());

        // Third period crosses the month boundary instead of snapping back to day 1 —
        // the accepted calendar drift of an anchored "quincenal".
        $third = $this->resolver->periodFor(Periodicity::BIWEEKLY, $second->dueDate->copy()->addDay(), 5);
        $this->assertEquals('2026-07-01', $third->periodStart->toDateString());
        $this->assertEquals('2026-07-15', $third->dueDate->toDateString());
    }

    // =========================================================================
    // EVERY_TWO_DAYS — anchored, NOT epoch-gridded (2 live QA policies)
    // =========================================================================

    /**
     * The regression this cadence exists to guard: valid_from 2026-07-01 has odd epoch
     * parity, so the deleted epoch grid opened the period on 2026-06-30 — one day BEFORE
     * the policy was valid. The anchored formula starts exactly on the anchor.
     */
    public function test_every_two_days_starts_exactly_on_the_anchor_never_a_day_earlier(): void
    {
        $run = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, Carbon::create(2026, 7, 1), 1);

        $this->assertEquals('2026-07-01', $run->periodStart->toDateString());
        $this->assertEquals('2026-07-02', $run->dueDate->toDateString());
        $this->assertEquals('2026-07-01', $run->issuedAt->toDateString());
        $this->assertEquals(1, $run->cycle, 'cycle = start day of the anchored period');
    }

    /**
     * Every start date is honored verbatim across a sweep crossing month boundaries —
     * there is no grid left that could pull a period back by a day.
     */
    public function test_every_two_days_honors_every_anchor_across_a_sweep(): void
    {
        $start = Carbon::create(2026, 1, 15); // sweep crosses the Jan→Feb boundary

        for ($i = 0; $i < 70; $i++) {
            $date = $start->copy()->addDays($i);
            $run = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, $date, 1);

            $this->assertEquals(
                $date->toDateString(),
                $run->periodStart->toDateString(),
                "Period must start on its anchor at day {$i} ({$date->toDateString()})",
            );
            $this->assertEquals(
                $date->copy()->addDay()->toDateString(),
                $run->dueDate->toDateString(),
                "Period must span exactly 2 days at day {$i} ({$date->toDateString()})",
            );
        }
    }

    /**
     * Contiguity is what guarantees no gaps and no overlaps once a policy has chained:
     * seeding from a prior period's end + 1 lands exactly on the next period's start.
     */
    public function test_every_two_days_chain_seed_identity(): void
    {
        $probe = Carbon::create(2026, 1, 1);

        for ($i = 0; $i < 40; $i++) {
            $anchor = $probe->copy()->addDays($i * 2);
            $run = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, $anchor, 1);

            $seed = $run->dueDate->copy()->addDay();
            $next = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, $seed, 1);

            $this->assertEquals($seed->toDateString(), $next->periodStart->toDateString());
        }

        // Explicit month-boundary case.
        $endOfJan = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, Carbon::create(2026, 1, 30), 1);
        $seed = $endOfJan->dueDate->copy()->addDay();
        $next = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, $seed, 1);
        $this->assertEquals($seed->toDateString(), $next->periodStart->toDateString());
    }

    /**
     * Continuity for the 2 LIVE QA policies: they are already chained, and a chained seed
     * (prior end + 1) resolves to the SAME period under the deleted epoch grid and under
     * the anchored formula. Only a brand-new policy's first period changes.
     */
    public function test_every_two_days_chained_seeds_match_the_deleted_epoch_grid(): void
    {
        $epoch = Carbon::create(1970, 1, 1)->startOfDay();
        $probe = Carbon::create(2026, 1, 1); // even epoch parity — a real grid boundary

        for ($i = 0; $i < 40; $i++) {
            // A chained seed is always a grid boundary: the prior period ended on an odd
            // epoch day, so seed = end + 1 has even parity.
            $seed = $probe->copy()->addDays($i * 2);
            $this->assertSame(0, (int) $epoch->diffInDays($seed) % 2, 'Fixture must be grid-aligned');

            $run = $this->resolver->periodFor(Periodicity::EVERY_TWO_DAYS, $seed, 1);

            // What the epoch grid would have returned for this same seed.
            $gridStart = $epoch->copy()->addDays(intdiv((int) $epoch->diffInDays($seed), 2) * 2);

            $this->assertEquals($gridStart->toDateString(), $run->periodStart->toDateString());
            $this->assertEquals($gridStart->copy()->addDay()->toDateString(), $run->dueDate->toDateString());
        }
    }
}
