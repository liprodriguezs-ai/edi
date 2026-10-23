<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Services\RuleSetLoading\CsvRuleSetLoader;
use Modules\Screening\Services\RuleSetLoading\DatabaseRuleSetLoader;
use Modules\Screening\Services\Scoring\AgeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REQ-SCO-002 -- CSV-built RuleSets must serialize identically to
 * DB-built RuleSets, for both shipped versions, once the Instrument catalog
 * has been seeded.
 */
final class RuleSetParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new InstrumentSeeder)->run();
    }

    public static function versionProvider(): array
    {
        return [
            'edi-legacy-2013' => ['edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH],
            'edi-2022-2ed' => ['edi-2022-2ed', AgeResolver::MODE_CALENDAR],
        ];
    }

    #[DataProvider('versionProvider')]
    public function test_csv_and_database_loaders_produce_equal_rule_sets(string $versionKey, string $ageCalculationMode): void
    {
        $version = InstrumentVersion::query()->where('key', $versionKey)->firstOrFail();
        $this->assertTrue($version->isPublished(), "Fixture assumption: {$versionKey} must be published before parity comparison.");

        $csvRuleSet = (new CsvRuleSetLoader)->build($versionKey, $ageCalculationMode);
        $dbRuleSet = (new DatabaseRuleSetLoader)->build($versionKey);

        // assertEquals (not assertSame): PostgreSQL jsonb does not preserve
        // the original key order of the `condition` object -- CSV-decoded
        // JSON keeps insertion order, jsonb round-trips canonicalized. Same
        // content, different PHP array key order; content equality is what
        // REQ-SCO-002 asks for.
        $this->assertEquals($csvRuleSet->toArray(), $dbRuleSet->toArray());
    }

    /**
     * REQ-SCO-012 held ONLY while Screening was the code-only engine
     * skeleton (no persistence, no runtime surface). edi-screening-application
     * (design D1) is exactly the change that adds that surface on purpose:
     * `/api/screening*` and `/api/confirmatory_test*` now legitimately exist.
     * This assertion is superseded, not violated — kept as a boundary check
     * that route registration still lives ONLY under those two resources
     * (never inside the frozen Services\Scoring/RuleSetLoading paths).
     */
    public function test_module_boots_and_only_the_expected_screening_routes_exist(): void
    {
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $uri = strtolower($route->uri());
            if (! preg_match('#(^|/)screening($|/)#', $uri)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '#^api/screening(/.*)?$#',
                $uri,
                "route '{$uri}' looks like an unexpected screening-shaped route outside the registered screening resource."
            );
        }
    }
}
