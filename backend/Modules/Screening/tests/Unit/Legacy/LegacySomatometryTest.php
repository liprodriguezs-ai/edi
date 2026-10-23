<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit\Legacy;

use Modules\Screening\Services\Legacy\LegacySomatometry;
use PHPUnit\Framework\TestCase;

/**
 * The legacy somatometry columns are dirty and mixed-unit (README warns: weight
 * mixes kg and grams, birth weight too). This converts them to the new app's
 * canonical integer units (grams / millimetres), dropping implausible values to
 * null rather than importing garbage. Pure, no DB.
 */
final class LegacySomatometryTest extends TestCase
{
    private LegacySomatometry $s;

    protected function setUp(): void
    {
        $this->s = new LegacySomatometry;
    }

    public function test_height_cm_becomes_millimetres(): void
    {
        $this->assertSame(870, $this->s->heightMm('87'));
        $this->assertSame(1060, $this->s->heightMm('106'));
    }

    public function test_implausible_or_empty_height_is_null(): void
    {
        $this->assertNull($this->s->heightMm('1'));    // 10 mm — impossible
        $this->assertNull($this->s->heightMm('201'));  // 2010 mm — impossible
        $this->assertNull($this->s->heightMm(''));
        $this->assertNull($this->s->heightMm(null));
    }

    public function test_weight_kilograms_and_grams_both_normalize_to_grams(): void
    {
        $this->assertSame(19000, $this->s->weightGrams('19'));      // 19 kg
        $this->assertSame(10000, $this->s->weightGrams('10.0'));    // 10 kg
        $this->assertSame(5800, $this->s->weightGrams('5800'));     // already grams
    }

    public function test_plausible_weight_in_grams_is_kept(): void
    {
        $this->assertSame(8800, $this->s->weightGrams('8800')); // 8.8 kg
    }

    public function test_implausible_or_empty_weight_is_null(): void
    {
        $this->assertNull($this->s->weightGrams('0'));
        $this->assertNull($this->s->weightGrams(''));
        $this->assertNull($this->s->weightGrams(null));
        $this->assertNull($this->s->weightGrams('100'));   // 100 kg — impossible
    }

    public function test_head_circumference_cm_becomes_millimetres(): void
    {
        $this->assertSame(490, $this->s->headCircumferenceMm('49'));
        $this->assertSame(500, $this->s->headCircumferenceMm(50));
        $this->assertNull($this->s->headCircumferenceMm('8'));   // 80 mm — impossible
        $this->assertNull($this->s->headCircumferenceMm(null));
    }

    public function test_birth_weight_mixed_units_normalize_to_grams(): void
    {
        $this->assertSame(3350, $this->s->birthWeightGrams('3.350'));  // kg
        $this->assertSame(2800, $this->s->birthWeightGrams('2800'));   // grams
        $this->assertSame(1850, $this->s->birthWeightGrams('1.85'));   // kg, premature but plausible
        $this->assertNull($this->s->birthWeightGrams('33150'));        // out of plausible range
        $this->assertNull($this->s->birthWeightGrams(''));
    }
}
