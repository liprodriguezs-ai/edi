<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\MexicanRfc;
use App\Rules\PhoneNumber;
use PHPUnit\Framework\TestCase;

/**
 * §18.7 — Reusable global validation rules (RFC + phone).
 */
final class ValidationRulesTest extends TestCase
{
    /**
     * @param  mixed  $value
     */
    private function fails($rule, $value): bool
    {
        $failed = false;
        $rule->validate('field', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    public function test_valid_rfcs_pass(): void
    {
        $rule = new MexicanRfc;

        // Persona moral (12: 3 letters) and persona física (13: 4 letters).
        $this->assertFalse($this->fails($rule, 'PLE260101AB1'));
        $this->assertFalse($this->fails($rule, 'XAXX010101000'));
        $this->assertFalse($this->fails($rule, 'FMMA801234AB1'));
        // Case-insensitive.
        $this->assertFalse($this->fails($rule, 'ple260101ab1'));
        // Null/empty are allowed (nullable handled separately).
        $this->assertFalse($this->fails($rule, null));
        $this->assertFalse($this->fails($rule, ''));
    }

    public function test_invalid_rfcs_fail(): void
    {
        $rule = new MexicanRfc;

        $this->assertTrue($this->fails($rule, 'NOT-AN-RFC'));
        $this->assertTrue($this->fails($rule, '12345'));
        $this->assertTrue($this->fails($rule, 'AB260101AB1'));        // only 2 leading letters
        $this->assertTrue($this->fails($rule, 'PLEABCDEFAB1'));       // letters where the date digits go
    }

    public function test_valid_phones_pass(): void
    {
        $rule = new PhoneNumber;

        $this->assertFalse($this->fails($rule, '7774441234'));
        $this->assertFalse($this->fails($rule, '+527774441234'));
        $this->assertFalse($this->fails($rule, '777-444-1234'));
        $this->assertFalse($this->fails($rule, '(777) 444 1234'));
        $this->assertFalse($this->fails($rule, null));
        $this->assertFalse($this->fails($rule, ''));
    }

    public function test_invalid_phones_fail(): void
    {
        $rule = new PhoneNumber;

        $this->assertTrue($this->fails($rule, '123'));               // too short
        $this->assertTrue($this->fails($rule, 'phone-number'));      // letters
        $this->assertTrue($this->fails($rule, '+1234567890123456')); // 16 digits, too long
    }
}
