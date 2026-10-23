<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use Modules\Screening\Services\RuleSetLoading\DatabaseRuleSetLoader;
use Modules\Screening\Services\Scoring\Values\RuleSet;

/**
 * Memoizes {@see DatabaseRuleSetLoader::build()} per version key (design
 * D18). RuleSet construction reads ~1,700 rows; per-request rebuilds would
 * dominate score latency. RuleSet is immutable, so this in-memory cache is
 * safe under Octane workers -- registered as a singleton by
 * ScreeningServiceProvider so it survives across requests within a worker.
 */
final class RuleSetProvider
{
    /** @var array<string, RuleSet> */
    private array $cache = [];

    public function __construct(
        private readonly DatabaseRuleSetLoader $loader = new DatabaseRuleSetLoader,
    ) {}

    public function for(string $versionKey): RuleSet
    {
        return $this->cache[$versionKey] ??= $this->loader->build($versionKey);
    }
}
