<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CalendarDays;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * trial-countdown-utility — CalendarDays::until() is the ONE product-wide
 * "days left" definition: whole calendar days, clamped at 0, never a
 * rounded-elapsed-time count. Uses the Laravel-aware TestCase (not the plain
 * PHPUnit one other app/Support unit tests use) because the helper calls the
 * `now()` facade helper, which needs the container bound.
 */
final class CalendarDaysTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_day_one_of_a_thirty_day_trial_reads_thirty_not_thirty_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));

        $end = Carbon::parse('2026-09-06 09:00:00'); // 30 days from "now"

        $this->assertSame(30, CalendarDays::until($end));
    }

    public function test_the_expiry_day_itself_reads_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 23:00:00'));

        $end = Carbon::parse('2026-08-07 09:00:00'); // same calendar day as "now"

        $this->assertSame(0, CalendarDays::until($end));
    }

    public function test_a_past_end_date_clamps_to_zero_never_negative(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));

        $end = Carbon::parse('2026-08-01 09:00:00'); // 6 days in the past

        $this->assertSame(0, CalendarDays::until($end));
    }

    public function test_null_end_returns_null(): void
    {
        $this->assertNull(CalendarDays::until(null));
    }
}
