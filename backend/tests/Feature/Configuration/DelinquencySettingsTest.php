<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for GET/PUT /config/delinquency-settings (design §6.1, R-DELQ-8).
 *
 * The endpoint is implemented in ConfigurationController and reuses the
 * read.configuration / update.configuration abilities — no separate
 * delinquency_config permission, cloning the getPackageSettings pattern
 * (flat keys, NOT the dotted-key getCollectionSettings).
 */
final class DelinquencySettingsTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeRoleUser(Tenant $tenant, string $role): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    // =========================================================================
    // Seeding contract — exactly 6 delinquency.* rows after TenantCreated
    // =========================================================================

    public function test_exactly_six_delinquency_settings_seeded_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $count = 0;
        $tenant->run(function () use (&$count): void {
            $count = Setting::where('slug', 'like', 'delinquency.%')->count();
        });

        $this->assertSame(6, $count, 'CollectionsDatabaseSeeder must create exactly 6 delinquency.* Setting rows');
    }

    // =========================================================================
    // GET /config/delinquency-settings
    // =========================================================================

    public function test_admin_get_returns_all_six_keys_at_defaults(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $data = $this->tenantRequest($this->token($admin, ['read.configuration']), $tenant)
            ->getJson('/api/config/delinquency-settings')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('restrictions_enabled', $data);
        $this->assertArrayHasKey('min_overdue_amount', $data);
        $this->assertArrayHasKey('block_package_intake', $data);
        $this->assertArrayHasKey('block_amenities', $data);
        $this->assertArrayHasKey('block_visitor_access', $data);
        $this->assertArrayHasKey('notice_text', $data);

        // All defaults ship DISABLED.
        $this->assertFalse($data['restrictions_enabled']);
        $this->assertFalse($data['block_package_intake']);
        $this->assertFalse($data['block_amenities']);
        $this->assertFalse($data['block_visitor_access']);
        $this->assertNotEmpty($data['notice_text']);

        // min_overdue_amount crosses the API boundary as a Money object (rule 17).
        $this->assertIsArray($data['min_overdue_amount']);
        $this->assertArrayHasKey('amount', $data['min_overdue_amount']);
        $this->assertArrayHasKey('currency', $data['min_overdue_amount']);
        $this->assertArrayHasKey('formatted', $data['min_overdue_amount']);
        $this->assertSame('0', $data['min_overdue_amount']['amount']);
    }

    public function test_get_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => DelinquencySettings::RESTRICTIONS_ENABLED],
                [
                    'name' => DelinquencySettings::RESTRICTIONS_ENABLED,
                    'slug' => DelinquencySettings::RESTRICTIONS_ENABLED,
                    'value' => true,
                    'type' => Setting::TYPE_BOOLEAN,
                ]
            );
        });

        $adminB = $this->makeRoleUser($tenantB, 'administrator');

        $data = $this->tenantRequest($this->token($adminB, ['read.configuration']), $tenantB)
            ->getJson('/api/config/delinquency-settings')
            ->assertOk()
            ->json('data');

        $this->assertFalse(
            $data['restrictions_enabled'],
            'Tenant B must not see Tenant A restrictions_enabled=true override'
        );
    }

    public function test_security_role_get_returns_403(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['read.package']), $tenant)
            ->getJson('/api/config/delinquency-settings')
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /config/delinquency-settings
    // =========================================================================

    public function test_partial_update_changes_only_the_sent_slug(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');
        $token = $this->token($admin, ['update.configuration', 'read.configuration']);

        $response = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/delinquency-settings', ['block_amenities' => true])
            ->assertOk()
            ->json('data');

        $this->assertTrue($response['block_amenities']);

        $data = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/delinquency-settings')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['block_amenities'], 'Updated slug must persist');
        $this->assertFalse($data['restrictions_enabled'], 'Untouched slug must stay at default');
        $this->assertFalse($data['block_visitor_access'], 'Untouched slug must stay at default');
    }

    public function test_pesos_convert_to_minor_units_on_persist(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');
        $token = $this->token($admin, ['update.configuration', 'read.configuration']);

        // 5,000 pesos, already converted to minor units by the frontend before sending.
        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/delinquency-settings', ['min_overdue_amount' => 500_000])
            ->assertOk();

        $tenant->run(function (): void {
            $this->assertSame(500_000, Setting::getValue(DelinquencySettings::MIN_OVERDUE_AMOUNT));
        });

        $data = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/delinquency-settings')
            ->assertOk()
            ->json('data');

        $this->assertSame('500000', $data['min_overdue_amount']['amount']);
    }

    /**
     * sdd-verify finding S-1: the 'integer' validation rule accepts a numeric
     * STRING, and `is_int()` alone would then misclassify it as TYPE_STRING on
     * persist, contradicting R-DELQ-8 ("MUST use Setting::TYPE_INTEGER"). A
     * JSON body with `"500000"` (string) decodes server-side to a PHP string —
     * exactly the case that slips past a naive `is_int($value)` check.
     */
    public function test_numeric_string_amount_still_persists_as_type_integer(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');
        $token = $this->token($admin, ['update.configuration']);

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/delinquency-settings', ['min_overdue_amount' => '500000'])
            ->assertOk();

        $tenant->run(function (): void {
            $row = Setting::where('slug', DelinquencySettings::MIN_OVERDUE_AMOUNT)->firstOrFail();
            $this->assertSame(Setting::TYPE_INTEGER, $row->type, 'A numeric-string amount must still persist as TYPE_INTEGER');
            $this->assertSame(500_000, Setting::getValue(DelinquencySettings::MIN_OVERDUE_AMOUNT));
        });
    }

    public function test_negative_amount_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['update.configuration']), $tenant)
            ->putJson('/api/config/delinquency-settings', ['min_overdue_amount' => -1])
            ->assertUnprocessable();
    }

    public function test_notice_text_over_500_chars_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['update.configuration']), $tenant)
            ->putJson('/api/config/delinquency-settings', ['notice_text' => str_repeat('a', 501)])
            ->assertUnprocessable();
    }

    public function test_security_role_put_returns_403(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->putJson('/api/config/delinquency-settings', ['block_amenities' => true])
            ->assertForbidden();
    }
}
