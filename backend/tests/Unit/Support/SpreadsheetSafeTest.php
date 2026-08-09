<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SpreadsheetSafe;
use PHPUnit\Framework\TestCase;

/**
 * Plan 059 — SpreadsheetSafe::cell() de-fangs formula-leading export cells.
 */
final class SpreadsheetSafeTest extends TestCase
{
    public function test_formula_leading_value_is_prefixed_with_apostrophe(): void
    {
        $this->assertSame("'=cmd", SpreadsheetSafe::cell('=cmd'));
    }

    public function test_plain_text_is_returned_unchanged(): void
    {
        $this->assertSame('hola', SpreadsheetSafe::cell('hola'));
    }

    public function test_empty_string_is_returned_unchanged(): void
    {
        $this->assertSame('', SpreadsheetSafe::cell(''));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousPrefixProvider(): array
    {
        return [
            'equals (formula)' => ['=HYPERLINK("http://evil.test","click")'],
            'plus' => ['+cmd|/c calc'],
            'minus' => ['-2+3+cmd|/c calc'],
            'at (DDE)' => ['@SUM(1+1)'],
            'tab' => ["\tsneaky"],
            'carriage return' => ["\rsneaky"],
        ];
    }

    /**
     * @dataProvider dangerousPrefixProvider
     */
    public function test_every_dangerous_prefix_is_escaped(string $value): void
    {
        $result = SpreadsheetSafe::cell($value);

        $this->assertSame("'".$value, $result);
        $this->assertSame("'", $result[0], 'Escaped value must start with an apostrophe');
    }

    public function test_value_with_dangerous_character_not_in_leading_position_is_untouched(): void
    {
        // Only the FIRST character matters — a legitimate value that merely
        // contains "=" or "-" mid-string must not be mangled.
        $this->assertSame('total = -5', SpreadsheetSafe::cell('total = -5'));
    }
}
