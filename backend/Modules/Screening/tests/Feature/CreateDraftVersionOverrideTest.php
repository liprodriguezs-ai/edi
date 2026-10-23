<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Models\Screening;
use Modules\Screening\Services\ScreeningApplicationService;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * createDraft normally pins the default published instrument version. The legacy
 * migration needs to score historical screenings under the version they were
 * actually applied with (`edi-legacy-2013`), so it may pin an explicit version.
 */
final class CreateDraftVersionOverrideTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_create_draft_pins_to_the_requested_instrument_version(): void
    {
        $tenant = $this->makeTenant();
        tenancy()->initialize($tenant);
        $this->seedPlatformRegistry();

        $legacy = InstrumentVersion::where('key', 'edi-legacy-2013')->firstOrFail();
        $default = $this->defaultVersion();
        $this->assertNotSame($legacy->id, $default->id, 'default must not already be the legacy version');

        ['visit' => $visit] = $this->makeChildVisit(500);

        app(ScreeningApplicationService::class)->createDraft([
            'visit_id' => $visit->id,
            'instrument_version_key' => 'edi-legacy-2013',
        ]);

        $screening = Screening::where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame($legacy->id, $screening->instrument_version_id);
    }

    public function test_create_draft_without_key_uses_the_default_version(): void
    {
        $tenant = $this->makeTenant();
        tenancy()->initialize($tenant);
        $this->seedPlatformRegistry();

        $default = $this->defaultVersion();
        ['visit' => $visit] = $this->makeChildVisit(500);

        app(ScreeningApplicationService::class)->createDraft(['visit_id' => $visit->id]);

        $screening = Screening::where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame($default->id, $screening->instrument_version_id);
    }
}
