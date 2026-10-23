<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use DateTimeImmutable;
use Modules\Screening\Services\Scoring\AgeResolver;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-003 / design D6 -- chronological + corrected age.
 */
final class AgeResolverTest extends TestCase
{
    private AgeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AgeResolver;
    }

    public function test_calendar_mode_is_exact_day_difference(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2023-01-15'),
            new DateTimeImmutable('2023-02-10'),
            AgeResolver::MODE_CALENDAR,
            null,
        );

        $this->assertSame(26, $ages['chronological_age_days']);
    }

    public function test_thirty_day_month_borrows_days_from_the_month(): void
    {
        // Calendar truth is 26 days; thirty_day_month treats January as a
        // 30-day month, so the borrow yields 25.
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2023-01-15'),
            new DateTimeImmutable('2023-02-10'),
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            null,
        );

        $this->assertSame(25, $ages['chronological_age_days']);
    }

    public function test_thirty_day_month_borrows_a_year_too(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2020-06-20'),
            new DateTimeImmutable('2021-02-10'),
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            null,
        );

        $this->assertSame(230, $ages['chronological_age_days']);
    }

    public function test_thirty_day_month_treats_every_month_as_thirty_days(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2024-01-01'),
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            null,
        );

        $this->assertSame(360, $ages['chronological_age_days']);
    }

    public function test_no_gestational_age_means_no_corrected_age(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2023-06-01'),
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            null,
        );

        $this->assertNull($ages['corrected_age_days']);
    }

    public function test_correction_applies_within_all_three_gates(): void
    {
        // 100 days chronological (thirty_day_month), 32 weeks gestational.
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2023-04-11'), // 100 days in thirty_day_month
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            32,
        );

        $this->assertSame(100, $ages['chronological_age_days']);
        $this->assertSame(44, $ages['corrected_age_days']); // 100 - (40-32)*7 = 44
    }

    public function test_a_premature_baby_is_corrected_even_at_fifty_nine_days(): void
    {
        // Manual EDI 2021 p.9 and the DGIS instructivo set no minimum age: every
        // child born before 37 weeks and under 2 years is corrected.
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2021-01-05'),
            new DateTimeImmutable('2021-03-04'), // exactly 59 days, thirty_day_month
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            30,
        );

        $this->assertSame(59, $ages['chronological_age_days']);
        $this->assertSame(-11, $ages['corrected_age_days']); // 59 - (40-30)*7
    }

    public function test_gestational_age_at_the_thirty_seven_week_gate_blocks_correction(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2023-04-11'), // 100 days
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            37,
        );

        $this->assertNull($ages['corrected_age_days']);
    }

    public function test_thirty_day_month_two_years_gate_blocks_at_seven_hundred_twenty_days(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2021-01-05'),
            new DateTimeImmutable('2023-01-05'), // exactly 720 days, thirty_day_month
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            30,
        );

        $this->assertSame(720, $ages['chronological_age_days']);
        $this->assertNull($ages['corrected_age_days']);
    }

    public function test_thirty_day_month_two_years_gate_allows_at_seven_hundred_nineteen_days(): void
    {
        $ages = $this->resolver->resolveAges(
            new DateTimeImmutable('2021-01-05'),
            new DateTimeImmutable('2023-01-04'), // 719 days, thirty_day_month
            AgeResolver::MODE_THIRTY_DAY_MONTH,
            30,
        );

        $this->assertSame(719, $ages['chronological_age_days']);
        $this->assertNotNull($ages['corrected_age_days']);
    }

    public function test_calendar_two_years_gate_uses_calendar_months_not_a_day_count(): void
    {
        $birth = new DateTimeImmutable('2021-01-10');

        $atGate = $this->resolver->resolveAges($birth, $birth->modify('+24 months'), AgeResolver::MODE_CALENDAR, 30);
        $this->assertNull($atGate['corrected_age_days']);

        $beforeGate = $this->resolver->resolveAges($birth, $birth->modify('+24 months')->modify('-1 day'), AgeResolver::MODE_CALENDAR, 30);
        $this->assertNotNull($beforeGate['corrected_age_days']);
    }

    public function test_unknown_age_calculation_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolveAges(new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-02-01'), 'bogus_mode', null);
    }

    /**
     * DateInterval::$days is always non-negative -- the direction lives in
     * ->invert. Reading it raw turned a visit dated BEFORE birth into a large
     * positive age, so an 11-month-old was scored as a five-year-old against the
     * wrong item bank, and the result looked perfectly ordinary.
     */
    public function test_a_test_date_before_birth_yields_a_negative_age(): void
    {
        $ages = (new AgeResolver)->resolveAges(
            new DateTimeImmutable('2025-08-27'),
            new DateTimeImmutable('2020-01-01'),
            AgeResolver::MODE_CALENDAR,
            null,
        );

        $this->assertLessThan(0, $ages['chronological_age_days']);
    }
}
