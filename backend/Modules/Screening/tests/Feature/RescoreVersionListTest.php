<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Rescoring lets a clinician re-run a screening under a different instrument
 * version, so the dialog has to offer the versions to pick from. It was reading
 * them straight off /instrument_version, which no organization role may touch --
 * deliberately, and stated in the permission seed: the raw catalog is a
 * platform-admin tool and "the Screening module will serve tenant-facing reads
 * through its own endpoints, never by browsing the raw instrument".
 *
 * The result was a feature nobody could use: the role holding the screenings
 * cannot read the catalog, and the role that can read it holds no screenings.
 * This locks in the endpoint the design always called for.
 */
final class RescoreVersionListTest extends TestCase
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

    public function test_an_organization_role_can_list_the_versions_it_may_rescore_with(): void
    {
        [$tenantA] = $this->twoTenants();
        (new InstrumentSeeder)->run();

        // A role holding ONLY rescore.screening, so a pass cannot come from some
        // broader grant. App\Models\Role is the Spatie-backed one; QCH\Core\Models\Role
        // is a plain read-model with no givePermissionTo().
        $role = Role::create(['name' => 'rescorer-'.Str::random(8), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::findOrCreate('rescore.screening', 'api'));

        $user = $this->makeUser($tenantA);
        $user->assignRole($role->name);
        $token = $user->createToken('rescorer', ['rescore.screening'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/screening/instrument-versions')
            ->assertOk();

        $this->assertNotEmpty($response->json('data'), 'No instrument version was offered to rescore with.');
        $this->assertArrayHasKey('key', $response->json('data.0'));
    }
}
