<?php

declare(strict_types=1);

namespace Modules\Instrument\Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
use QCH\Core\Models\Role;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-INS-014 — access control and platform registration (task 5.8). The
 * instrument API is read-only and super-admin-exclusive (D22/D23): no
 * tenant role gets ANY verb, including read.
 */
final class InstrumentApiPermissionTest extends TestCase
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

    private function seedPlatformRegistry(): void
    {
        (new CatalogSeeder)->run();
        (new InstrumentSeeder)->run();
        Artisan::call('db:seed', ['--class' => ModuleTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SystemTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PermissionsTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolePermissionTableSeeder::class, '--force' => true]);
    }

    private function centralUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    public function test_super_admin_can_list_and_show_versions_with_no_legacy_id_in_payload(): void
    {
        $this->seedPlatformRegistry();

        // The role is what makes this actor a super admin -- a wildcard token alone
        // never does in production, where abilities are permission names. Matches
        // test_menu_shows_instrumento_edi_under_core_for_super_admin_only below.
        $user = $this->centralUser();
        $user->assignRole(Role::SLUG_SUPER_ADMIN);
        $token = $user->createToken('super', ['*'])->plainTextToken;

        $index = $this->withToken($token)->getJson('/api/instrument_version')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($index->json('data')));

        $versionId = $index->json('data.0.id');
        $show = $this->withToken($token)
            ->getJson("/api/screening_item?filters[0][field]=instrument_version_id&filters[0][operator]=%3D&filters[0][value]={$versionId}")
            ->assertOk();

        $raw = $show->getContent();
        $this->assertStringNotContainsString('legacy_id', $raw, 'legacy_id must never appear in any API payload (REQ-INS-004).');
    }

    public function test_tenant_administrator_is_denied_read_on_every_instrument_route(): void
    {
        $this->seedPlatformRegistry();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $user->assignRole(Role::SLUG_ADMINISTRATOR);
        $token = $user->createToken('admin', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;

        foreach ([
            'instrument_version', 'age_group', 'screening_item', 'area_scoring_rule',
            'global_scoring_rule', 'risk_factor', 'head_circumference_reference', 'global_result_message',
        ] as $route) {
            $this->tenantRequest($token, $tenant)
                ->getJson("/api/{$route}")
                ->assertStatus(403);
        }
    }

    public function test_no_write_routes_exist_for_any_instrument_resource(): void
    {
        $this->seedPlatformRegistry();

        $user = $this->centralUser();
        $token = $user->createToken('super', ['*'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/screening_item', ['code' => 'X']);
        $this->assertContains($response->status(), [404, 405]);

        $response = $this->withToken($token)->postJson('/api/instrument_version', ['key' => 'x']);
        $this->assertContains($response->status(), [404, 405]);
    }

    public function test_menu_shows_instrumento_edi_under_core_for_super_admin_only(): void
    {
        $this->seedPlatformRegistry();

        $superAdmin = $this->centralUser();
        $superAdmin->assignRole(Role::SLUG_SUPER_ADMIN);
        $superAdminToken = $superAdmin->createToken(
            'super', $superAdmin->getPermissionsViaRoles()->pluck('name')->toArray()
        )->plainTextToken;

        $this->app['auth']->forgetGuards();
        $superMenu = $this->withToken($superAdminToken)->getJson('/api/menu')->assertOk();
        $superModuleNames = collect($superMenu->json('data'))
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(function ($item) {
                if (($item['type'] ?? null) === 'group') {
                    return collect($item['modules'])->pluck('name');
                }

                return [$item['name'] ?? null];
            }))
            ->filter()
            ->values()
            ->all();
        $this->assertContains('Instrumento EDI', $superModuleNames);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $tenantUser = $this->makeUser($tenant);
        $tenantUser->assignRole(Role::SLUG_ADMINISTRATOR);
        $tenantToken = $tenantUser->createToken(
            'admin', $tenantUser->getPermissionsViaRoles()->pluck('name')->toArray()
        )->plainTextToken;

        $tenantMenu = $this->tenantRequest($tenantToken, $tenant)->getJson('/api/menu')->assertOk();
        $tenantModuleNames = collect($tenantMenu->json('data'))
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(function ($item) {
                if (($item['type'] ?? null) === 'group') {
                    return collect($item['modules'])->pluck('name');
                }

                return [$item['name'] ?? null];
            }))
            ->filter()
            ->values()
            ->all();
        $this->assertNotContains('Instrumento EDI', $tenantModuleNames);
    }
}
