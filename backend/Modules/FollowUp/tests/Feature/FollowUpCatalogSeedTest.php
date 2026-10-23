<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Models\ReferralSpecialty;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-FUP-001 coverage.
 */
final class FollowUpCatalogSeedTest extends TestCase
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

    public function test_central_catalogs_are_seeded_with_fixed_uuids_and_no_tenant_id(): void
    {
        (new CatalogSeeder)->run();

        $this->assertSame('Reaplicación a 3 meses', FollowUpType::find(FollowUpType::REASSESS_3_MONTHS)?->name);
        $this->assertSame(3, FollowUpType::count());
        $this->assertNull(FollowUpType::find(FollowUpType::REASSESS_3_MONTHS)?->tenant_id ?? null);

        $this->assertSame('Pendiente', FollowUpStatus::find(FollowUpStatus::PENDING)?->name);
        $this->assertSame(5, FollowUpStatus::count());
    }

    public function test_reseeding_central_catalogs_is_idempotent(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertSame(3, FollowUpType::count());
        $this->assertSame(5, FollowUpStatus::count());
    }

    public function test_new_tenant_gets_its_own_six_referral_specialties(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $names = ReferralSpecialty::pluck('name')->all();
            $this->assertContains('Neurología pediátrica', $names);
            $this->assertContains('Audiología', $names);
            $this->assertContains('Oftalmología', $names);
            $this->assertContains('Terapia de lenguaje', $names);
            $this->assertContains('Terapia física', $names);
            $this->assertContains('Psicología', $names);
            $this->assertCount(6, $names);
        });
    }

    public function test_reseeding_never_overwrites_a_tenant_edited_specialty(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            ReferralSpecialty::where('name', 'Audiología')->firstOrFail()->update(['name' => 'Audiología y Otorrinolaringología']);
        });

        $this->seedTenant($tenant);

        // Reseeding matches by name, so a renamed row is never mutated back;
        // it simply no longer matches 'Audiología' and keeps its new name
        // (same behavior proven for caregiver_relationship's
        // test_reseeding_preserves_tenant_customization).
        $tenant->run(function (): void {
            $this->assertDatabaseHas('referral_specialty', ['name' => 'Audiología y Otorrinolaringología']);
        });
    }
}
