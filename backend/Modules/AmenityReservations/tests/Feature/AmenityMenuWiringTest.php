<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S17 — menu wiring living contract. After `ResetPermissionTableSeeder` ingests
 * the `module_system.csv` rows and `SystemTableSeeder` mappings added in this
 * slice, `GET /api/menu` must surface "Amenidades" (`/amenity`) and
 * "Reservaciones" (`/amenity-reservation`) under the "Fraccionamiento" system.
 *
 * The SAME menu entry serves both audiences: `seedModulePermissionEntities()`
 * maps both to a PLAIN entity (no `update:` prefix), so `MenuController` shows
 * them to anyone holding `read.{entity}` — which residents hold since S2. The
 * admin↔resident split happens inside the frontend page itself
 * (`AmenityListPage`/`AmenityReservationListPage` redirect a viewer lacking
 * `update.{entity}` to `/amenities` / `/my-reservations`, mirroring the
 * established `PackageListPage` → `/my-packages` precedent), not via a second
 * "Mis…" module row.
 */
final class AmenityMenuWiringTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_administrator_sees_both_modules_under_fraccionamiento_with_admin_routes(): void
    {
        [$amenidades, $reservaciones] = $this->menuModulesFor('administrator');

        $this->assertNotNull($amenidades, 'Amenidades module missing from the menu for administrator.');
        $this->assertSame('/amenity', $amenidades['route']);
        $this->assertSame('IconBuildingCommunity', $amenidades['icon']);

        $this->assertNotNull($reservaciones, 'Reservaciones module missing from the menu for administrator.');
        $this->assertSame('/amenity-reservation', $reservaciones['route']);
        $this->assertSame('IconCalendarEvent', $reservaciones['icon']);
    }

    public function test_resident_with_read_only_abilities_sees_the_same_two_modules(): void
    {
        [$amenidades, $reservaciones] = $this->menuModulesFor('resident');

        $this->assertNotNull(
            $amenidades,
            'Resident (read.amenity) must see Amenidades in the menu — the frontend page redirects them, not the menu.'
        );
        $this->assertNotNull(
            $reservaciones,
            'Resident (read.amenity_reservation) must see Reservaciones in the menu.'
        );
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function menuModulesFor(string $roleSlug): array
    {
        $tenant = $this->makeTenant('Amenity Menu '.uniqid());
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant);
        $user->assignRole($roleSlug);

        $token = $user->createToken('menu-test', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)->getJson('/api/menu');
        $response->assertStatus(200);

        $fraccionamiento = collect($response->json('data'))->firstWhere('slug', 'fraccionamiento');
        $this->assertNotNull($fraccionamiento, 'Fraccionamiento system missing from the menu response.');

        $modules = collect($fraccionamiento['items'])->flatMap(
            fn (array $item) => ($item['type'] ?? null) === 'group' ? $item['modules'] : [$item]
        );

        return [
            $modules->firstWhere('name', 'Amenidades'),
            $modules->firstWhere('name', 'Reservaciones'),
        ];
    }
}
