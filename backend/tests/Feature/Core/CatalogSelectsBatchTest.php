<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * /selects answers every dropdown of a form in ONE batch, so its failure mode is
 * disproportionate: an unlisted relation raised "Unknown relation" and returned
 * 500, which left EVERY dropdown on the screen empty -- 8 of them on the
 * stimulation session form, making the form impossible to submit at all.
 */
final class CatalogSelectsBatchTest extends TestCase
{
    use RefreshDatabase;
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

    public function test_a_catalog_may_eager_load_its_declared_relation(): void
    {
        [$tenantA, $tokenA] = $this->twoTenants();

        $this->inTenant($tenantA, function (): void {
            $municipality = HealthCenterFactory::defaultMunicipality();
            $healthCenter = HealthCenter::factory()->create(['municipality_id' => $municipality->id]);
            PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);
        });

        $response = $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/selects', [
                'practitioner_profile' => ['_with' => 'user'],
            ])
            ->assertOk();

        // Assert the relation actually LOADED. Asserting only that the key exists
        // passes even when the catalog blew up, because the batch now degrades a
        // failing catalog to an empty list -- which is how this stayed broken while
        // the endpoint answered 200.
        $rows = $response->json('data.practitioner_profile');
        $this->assertNotEmpty($rows, 'The practitioner catalog came back empty, so its dropdown is still unusable.');
        $this->assertNotNull($rows[0]['user']['name'] ?? null, 'The eager-loaded user did not come through.');
    }

    /**
     * The batch must degrade one catalog at a time, never all of them at once.
     */
    public function test_one_failing_catalog_does_not_empty_the_others(): void
    {
        [$tenantA, $tokenA] = $this->twoTenants();

        $response = $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/selects', [
                'practitioner_profile' => ['_with' => 'does_not_exist'],
                'health_center' => [],
                'sex' => [],
            ])
            ->assertOk();

        $this->assertArrayHasKey('sex', $response->json('data'));
        $this->assertNotEmpty($response->json('data.sex'), 'A sibling catalog was emptied by an unrelated failure.');
    }
}
