<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Tests\TestCase;

/**
 * REQ-FUP-009. Mirrors Modules/Screening/tests/Feature/MenuVisibilityAndSelectsTest.php.
 */
final class FollowUpMenuRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use WithFollowUpFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function menuModuleEntries(array $json): array
    {
        return collect($json)
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(function ($item) {
                if (($item['type'] ?? null) === 'group') {
                    return collect($item['modules']);
                }

                return [$item];
            }))
            ->filter(fn ($item) => isset($item['name']))
            ->values()
            ->all();
    }

    public function test_menu_shows_seguimientos_and_especialidades_and_hides_referencias(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $menu = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk();
        $entries = $this->menuModuleEntries($menu->json('data'));
        $names = collect($entries)->pluck('name')->all();

        $this->assertContains('Seguimientos', $names);
        $this->assertContains('Especialidades de Referencia', $names);
        $this->assertNotContains('Referencias', $names);

        $seguimientos = collect($entries)->firstWhere('name', 'Seguimientos');
        $this->assertSame('/follow-up', $seguimientos['route'] ?? null);
        $this->assertNotEmpty($seguimientos['icon'] ?? null);
    }

    public function test_permissions_exist_and_gate_the_api_without_them(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $noPermUser = $this->inTenant($tenant, fn () => $this->makeUser($tenant));
        $token = $noPermUser->createToken('nobody', [])->plainTextToken;

        // Orion's own authorize() (Sanctum tokenCan) gates index/show/store/update
        // AFTER the auto-discovered FollowUpCreateRequest validates the body
        // (DelegatingModuleRequest convention), so a store call with an
        // invalid/empty body 422s before it ever reaches authorize(). index
        // has no body to validate, so it is the clean 403 proof here.
        $this->tenantRequest($token, $tenant)->getJson('/api/follow_up')->assertStatus(403);
        $this->tenantRequest($token, $tenant)->getJson('/api/referral')->assertStatus(403);
        $this->tenantRequest($token, $tenant)->getJson('/api/referral_specialty')->assertStatus(403);

        $this->inTenant($tenant, function () {
            $this->assertGreaterThan(0, \DB::table('permissions')->where('name', 'read.follow_up')->count());
            $this->assertGreaterThan(0, \DB::table('permissions')->where('name', 'read.referral')->count());
            $this->assertGreaterThan(0, \DB::table('permissions')->where('name', 'read.referral_specialty')->count());
        });
    }

    /**
     * Proves the Str::studly coupling between frontend CatalogType and
     * AngularController::$mapperSelects actually resolves.
     */
    public function test_selects_returns_the_three_new_followup_catalogs(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', [
            'follow_up_type' => [],
            'follow_up_status' => [],
            'referral_specialty' => [],
        ])->assertOk();

        $data = $response->json('data') ?? $response->json();

        $this->assertNotEmpty($data['follow_up_type'] ?? []);
        $this->assertNotEmpty($data['follow_up_status'] ?? []);
        $this->assertNotEmpty($data['referral_specialty'] ?? []);
    }
}
