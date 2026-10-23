<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * REQ-SCO-001 / design D2/§7 -- purity is a CHECKED property, not an
 * intention. Scans every file under `Modules/Screening/app/Services/Scoring`
 * for tokens that would make the engine impure (framework, wall clock,
 * randomness). The RuleSet LOADERS live in `Services/RuleSetLoading/`
 * precisely so this scan can stay absolute, with no exemption list.
 */
final class ScoringPurityTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private const FORBIDDEN_TOKENS = [
        'Illuminate\\Database',
        'Eloquent',
        'DB::',
        'now(',
        'Carbon::now',
        'CarbonImmutable::now',
        'request(',
        'config(',
        'env(',
        'app(',
        'resolve(',
        'rand(',
        'mt_rand',
        'random_',
        'uniqid',
        'time()',
        'date(',
    ];

    public function test_scoring_namespace_is_framework_free(): void
    {
        $scoringDir = dirname(__DIR__, 2).'/app/Services/Scoring';
        $this->assertDirectoryExists($scoringDir);

        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scoringDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($contents, $token)) {
                    $violations[] = "{$file->getPathname()} contains forbidden token \"{$token}\"";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
