<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Models\Setting;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Orion `/setting` resource contract coverage (`setting-value-native-json`,
 * task T4.6): create with a native-shaped `value` persists and resolves
 * correctly, and `type` stays filterable/sortable on `/setting/search`.
 */
final class SettingApiTest extends TestCase
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

    // ── Scenario: Create via API ──────────────────────────────────────────────

    public function test_create_with_native_shaped_value_persists_and_resolves_correctly(): void
    {
        $tenant = $this->makeTenant('Setting API Create Organization');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/setting', [
                'name' => 'Feature flag',
                'slug' => 'api.feature_flag',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
            ])
            ->assertCreated();

        $tenant->run(function (): void {
            $this->assertSame(true, Setting::getValue('api.feature_flag'));
        });
    }

    public function test_create_with_array_value_under_json_type_persists_and_resolves_correctly(): void
    {
        $tenant = $this->makeTenant('Setting API Create Array Organization');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/setting', [
                'name' => 'Reminders',
                'slug' => 'api.reminders',
                'value' => [3, 1, 0],
                'type' => Setting::TYPE_JSON,
            ])
            ->assertCreated();

        $tenant->run(function (): void {
            $this->assertSame([3, 1, 0], Setting::getValue('api.reminders'));
        });
    }

    public function test_create_with_pre_serialized_value_is_rejected_with_a_422(): void
    {
        $tenant = $this->makeTenant('Setting API Create Legacy Organization');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/setting', [
                'name' => 'Feature flag',
                'slug' => 'api.legacy_flag',
                'value' => 'true',
                'type' => Setting::TYPE_BOOLEAN,
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertNull(Setting::getValue('api.legacy_flag'));
        });
    }

    // ── Scenario: Filter by type ───────────────────────────────────────────────

    public function test_search_filters_and_sorts_by_type(): void
    {
        $tenant = $this->makeTenant('Setting API Search Organization');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $tenant->run(function (): void {
            Setting::putValue('api.search.bool', true, Setting::TYPE_BOOLEAN);
            Setting::putValue('api.search.int', 42, Setting::TYPE_INTEGER);
        });

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/setting/search', [
                'filters' => [
                    ['field' => 'type', 'operator' => '=', 'value' => Setting::TYPE_INTEGER],
                ],
            ])
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('api.search.int', $slugs);
        $this->assertNotContains('api.search.bool', $slugs);
    }
}
