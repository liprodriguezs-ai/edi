<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-013 (delta, edi-screening-ui) — Tamizajes ships its frontend and
 * becomes visible in the menu; Pruebas Confirmatorias has no standalone page
 * and stays hidden. Also proves the `Str::studly` selects coupling
 * (CatalogType ↔ AngularController::$mapperSelects) actually lands.
 *
 * Renamed from MenuHiddenTest (edi-screening-application), which asserted
 * the opposite ("both hidden") — that expectation flipped for Tamizajes in
 * this change.
 */
final class MenuVisibilityAndSelectsTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
    use WithTenantSetup;

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

    public function test_tamizajes_is_visible_and_confirmatorias_stays_hidden(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $menu = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk();
        $entries = $this->menuModuleEntries($menu->json('data'));
        $names = collect($entries)->pluck('name')->all();

        $this->assertContains('Prueba EDI', $names);
        $this->assertNotContains('Pruebas Confirmatorias', $names);

        $tamizajes = collect($entries)->firstWhere('name', 'Prueba EDI');
        $this->assertSame('/screening', $tamizajes['route'] ?? null);
        $this->assertNotEmpty($tamizajes['icon'] ?? null);
    }

    public function test_permissions_exist_and_gate_the_api_without_them(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $noPermUser = $this->inTenant($tenant, fn () => $this->makeUser($tenant));
        $token = $noPermUser->createToken('nobody', [])->plainTextToken;

        $this->tenantRequest($token, $tenant)->getJson('/api/screening')->assertStatus(403);
        $this->tenantRequest($token, $tenant)->postJson('/api/screening', [])->assertStatus(403);
        $this->tenantRequest($token, $tenant)->getJson('/api/confirmatory_test')->assertStatus(403);

        $this->inTenant($tenant, function () {
            $this->assertGreaterThan(0, \DB::table('permissions')->where('name', 'read.screening')->count());
            $this->assertGreaterThan(0, \DB::table('permissions')->where('name', 'rescore.screening')->count());
            $this->assertGreaterThan(0, \DB::table('permissions')->where('name', 'read.confirmatory_test')->count());
        });
    }

    /**
     * Proves the Str::studly coupling between frontend CatalogType and
     * AngularController::$mapperSelects actually resolves: a mismatch would
     * leave the dropdown silently empty (no error, REQ-SCR-013 delta note).
     */
    public function test_selects_returns_the_three_new_screening_catalogs(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        // Snake_case keys — exactly what CatalogType (frontend) sends. The
        // controller resolves the mapper via Str::studly($type), so this is
        // the real coupling proof (PascalCase request keys would pass
        // trivially since Str::studly is a no-op on them).
        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', [
            'screening_status' => [],
            'result_color' => [],
            'confirmatory_test_result' => [],
        ])->assertOk();

        $data = $response->json('data') ?? $response->json();

        $this->assertNotEmpty($data['screening_status'] ?? []);
        $this->assertNotEmpty($data['result_color'] ?? []);
        $this->assertNotEmpty($data['confirmatory_test_result'] ?? []);
    }
}
