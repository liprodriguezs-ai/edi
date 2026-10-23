<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * The three semaphore color keys (design D11). String constants, never a PHP
 * native enum (project hard rule 4) -- these mirror `result_color.key` and
 * are the stable contract with the future `situation`/`result_color`
 * catalogs.
 */
final class ResultColorKey
{
    public const GREEN = 'green';

    public const YELLOW = 'yellow';

    public const RED = 'red';

    /**
     * Severity order used by SituationResolver: green < yellow < red.
     */
    public static function severity(string $colorKey): int
    {
        return match ($colorKey) {
            self::GREEN => 0,
            self::YELLOW => 1,
            self::RED => 2,
            default => throw new \InvalidArgumentException("Unknown result color key '{$colorKey}'."),
        };
    }

    private function __construct() {}
}
