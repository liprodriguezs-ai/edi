<?php

declare(strict_types=1);

namespace Modules\Stimulation\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\BodyZone;
use Modules\Stimulation\Models\DidacticMaterial;
use Modules\Stimulation\Models\MassageMovement;
use Modules\Stimulation\Models\SessionSection;
use Modules\Stimulation\Models\Song;
use Modules\Stimulation\Models\StimulationArea;
use Modules\Stimulation\Models\StimulationDynamic;
use Modules\Stimulation\Models\StimulationGroupType;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-XC-001 coverage for the 9 Stimulation catalogs.
 */
final class CatalogSeedingTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // ── central catalogs ────────────────────────────────────────────────────

    public function test_central_catalogs_are_seeded_with_fixed_uuids(): void
    {
        (new CatalogSeeder)->run();

        $this->assertSame('Integración', SessionSection::find(SessionSection::INTEGRATION)?->name);
        $this->assertSame('Relajación', SessionSection::find(SessionSection::RELAXATION)?->name);
        $this->assertSame(4, SessionSection::count());

        $this->assertSame('Motora', StimulationArea::find(StimulationArea::MOTOR)?->name);
        $this->assertSame(5, StimulationArea::count());

        $this->assertSame('Beca', AttendancePaymentStatus::find(AttendancePaymentStatus::SCHOLARSHIP)?->name);
        $this->assertSame(3, AttendancePaymentStatus::count());
    }

    public function test_reseeding_central_catalogs_is_idempotent(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertSame(4, SessionSection::count());
        $this->assertSame(5, StimulationArea::count());
        $this->assertSame(3, AttendancePaymentStatus::count());
    }

    // ── tenant catalogs ─────────────────────────────────────────────────────

    public function test_new_tenant_gets_its_own_body_zone_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $names = BodyZone::pluck('name')->all();
            $this->assertContains('Cuello', $names);
            $this->assertContains('Espalda', $names);
            $this->assertCount(9, $names);

            $this->assertCount(4, StimulationDynamic::all());
            $this->assertCount(7, MassageMovement::all());
            $this->assertCount(0, DidacticMaterial::all());
            $this->assertCount(0, Song::all());
            $this->assertContains('Grupo abierto', StimulationGroupType::pluck('name')->all());
        });
    }

    public function test_reseeding_preserves_tenant_customization(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $row = BodyZone::where('name', 'Circuito')->first() ?? BodyZone::where('name', 'Cuello')->firstOrFail();
            $row->update(['name' => 'Cuello y nuca']);
        });

        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertDatabaseHas('body_zone', ['name' => 'Cuello y nuca']);
        });
    }

    public function test_tenant_catalogs_are_isolated_between_tenants(): void
    {
        [$tenantA, , $tenantB] = $this->twoTenants();

        $this->inTenant($tenantA, function (): void {
            BodyZone::where('name', 'Cuello')->firstOrFail()->update(['name' => 'Nuca']);
        });

        $tenantB->run(function (): void {
            $this->assertTrue(BodyZone::where('name', 'Cuello')->exists());
            $this->assertFalse(BodyZone::where('name', 'Nuca')->exists());
        });
    }
}
