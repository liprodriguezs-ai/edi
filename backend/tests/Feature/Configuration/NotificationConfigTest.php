<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Database\Seeders\TenantNotificationSettingSeeder;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationSettingBoardRole;
use Modules\Notification\Services\NotificationSettingService;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for GET /config/notifications and PUT /config/notifications.
 *
 * Covers:
 *  - GET returns events + board_roles for the current tenant
 *  - GET falls back to event defaults when no tenant setting row exists
 *  - PUT persists channels and is_active
 *  - PUT persists board_role_ids for board-audience events
 *  - PUT ignores board_role_ids for resident-audience events
 *  - Tenant isolation: tenant A's PUT does not affect tenant B
 */
final class NotificationConfigTest extends TestCase
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
    // GET /config/notifications
    // =========================================================================

    public function test_get_returns_events_and_board_roles_for_tenant(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notifications');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('board_roles', $data);
        $this->assertNotEmpty($data['events']);

        // Each event must have the expected shape.
        $event = $data['events'][0];
        $this->assertArrayHasKey('key', $event);
        $this->assertArrayHasKey('name', $event);
        $this->assertArrayHasKey('audience', $event);
        $this->assertArrayHasKey('is_active', $event);
        $this->assertArrayHasKey('channel_email', $event);
        $this->assertArrayHasKey('channel_push', $event);
        $this->assertArrayHasKey('channel_whatsapp', $event);
        $this->assertArrayHasKey('board_role_ids', $event);
    }

    public function test_get_falls_back_to_event_defaults_when_no_setting_row_exists(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // No tenant notification_setting rows seeded — defaults must come from the event.
        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notifications');

        $response->assertOk();
        $events = $response->json('data.events');
        $this->assertNotEmpty($events);

        foreach ($events as $ev) {
            // Without a setting row, is_active must default to true.
            $this->assertTrue($ev['is_active'], "Event {$ev['key']} should default to is_active=true");
            // board_role_ids must be an empty array (no setting row = no saved roles).
            $this->assertIsArray($ev['board_role_ids']);
        }
    }

    public function test_board_roles_only_includes_active_roles(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notifications');

        $response->assertOk();
        $boardRoles = $response->json('data.board_roles');

        // All returned roles must be active ones (CatalogSeeder seeds 5 board_roles, all active).
        $this->assertNotEmpty($boardRoles);
        foreach ($boardRoles as $role) {
            $this->assertArrayHasKey('id', $role);
            $this->assertArrayHasKey('name', $role);
        }
    }

    // =========================================================================
    // PUT /config/notifications
    // =========================================================================

    public function test_put_persists_channels_and_is_active(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $event = NotificationEvent::where('key', 'payment_due_reminder')->firstOrFail();

        $payload = [
            'events' => [
                [
                    'key' => 'payment_due_reminder',
                    'is_active' => false,
                    'channel_email' => true,
                    'channel_push' => false,
                    'channel_whatsapp' => false,
                    'board_role_ids' => [],
                ],
            ],
        ];

        $response = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/notifications', $payload);

        $response->assertOk();
        $response->assertJsonFragment(['message' => 'Configuración de notificaciones actualizada.']);

        // Verify the setting was persisted in the tenant context.
        $tenant->run(function () use ($event): void {
            $setting = NotificationSetting::where('notification_event_id', $event->id)->first();
            $this->assertNotNull($setting, 'NotificationSetting row must exist after PUT');
            $this->assertFalse($setting->is_active);
            $this->assertTrue($setting->channel_email);
            $this->assertFalse($setting->channel_push);
            $this->assertFalse($setting->channel_whatsapp);
        });
    }

    public function test_put_persists_board_role_ids_for_board_audience_events(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // payment_registered is audience=board.
        $event = NotificationEvent::where('key', 'payment_registered')->firstOrFail();
        $this->assertSame('board', $event->audience, 'payment_registered must have audience=board');

        $presidentId = BoardRole::PRESIDENT;
        $treasurerId = BoardRole::TREASURER;

        $payload = [
            'events' => [
                [
                    'key' => 'payment_registered',
                    'is_active' => true,
                    'channel_email' => true,
                    'channel_push' => true,
                    'channel_whatsapp' => false,
                    'board_role_ids' => [$presidentId, $treasurerId],
                ],
            ],
        ];

        $response = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/notifications', $payload);

        $response->assertOk();

        $tenant->run(function () use ($event, $presidentId, $treasurerId): void {
            $setting = NotificationSetting::where('notification_event_id', $event->id)->first();
            $this->assertNotNull($setting);

            $roleIds = NotificationSettingBoardRole::where('notification_setting_id', $setting->id)
                ->pluck('board_role_id')
                ->sort()
                ->values()
                ->all();

            $expected = collect([$presidentId, $treasurerId])->sort()->values()->all();
            $this->assertSame($expected, $roleIds);
        });
    }

    public function test_put_ignores_board_role_ids_for_resident_audience_events(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // payment_due_reminder is audience=resident.
        $event = NotificationEvent::where('key', 'payment_due_reminder')->firstOrFail();
        $this->assertSame('resident', $event->audience, 'payment_due_reminder must have audience=resident');

        $payload = [
            'events' => [
                [
                    'key' => 'payment_due_reminder',
                    'is_active' => true,
                    'channel_email' => true,
                    'channel_push' => false,
                    'channel_whatsapp' => false,
                    // Should be silently ignored for resident-audience events.
                    'board_role_ids' => [BoardRole::PRESIDENT],
                ],
            ],
        ];

        $response = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/notifications', $payload);

        $response->assertOk();

        $tenant->run(function () use ($event): void {
            $setting = NotificationSetting::where('notification_event_id', $event->id)->first();
            $this->assertNotNull($setting);

            // No board role pivot rows must exist for a resident-audience event.
            $pivotCount = NotificationSettingBoardRole::where('notification_setting_id', $setting->id)->count();
            $this->assertSame(0, $pivotCount, 'board_role_ids must be silently ignored for resident-audience events');
        });
    }

    public function test_tenant_isolation_put_on_tenant_a_does_not_affect_tenant_b(): void
    {
        (new CatalogSeeder)->run();

        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $userA = $this->makeUser($tenantA);
        $tokenA = $userA->createToken('adminA', ['*'])->plainTextToken;

        $event = NotificationEvent::where('key', 'payment_due_reminder')->firstOrFail();

        // PUT on tenant A: disable the event.
        $this->tenantRequest($tokenA, $tenantA)
            ->putJson('/api/config/notifications', [
                'events' => [[
                    'key' => 'payment_due_reminder',
                    'is_active' => false,
                    'channel_email' => false,
                    'channel_push' => false,
                    'channel_whatsapp' => false,
                    'board_role_ids' => [],
                ]],
            ])
            ->assertOk();

        // Tenant B must have no setting row (no cross-tenant leak).
        $tenantB->run(function () use ($event): void {
            $setting = NotificationSetting::where('notification_event_id', $event->id)->first();
            $this->assertNull($setting, 'Tenant B must not inherit tenant A settings');
        });
    }

    public function test_get_requires_read_configuration_ability(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        // Token with no abilities — missing read.configuration.
        $token = $user->createToken('test', ['write.other'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notifications');

        $response->assertForbidden();
    }

    public function test_put_requires_update_configuration_ability(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['read.configuration'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/notifications', ['events' => []]);

        $response->assertForbidden();
    }

    // =========================================================================
    // is_mandatory enforcement (Phase 0 backbone)
    // =========================================================================

    public function test_mandatory_event_cannot_be_disabled_and_is_flagged_in_payload(): void
    {
        (new CatalogSeeder)->run();

        // No catalog event is mandatory by default; force one for this test.
        NotificationEvent::where('key', 'payment_due_reminder')->update(['is_mandatory' => true]);
        $event = NotificationEvent::where('key', 'payment_due_reminder')->firstOrFail();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // Client tries to DISABLE the mandatory event (and toggles a channel).
        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/notifications', [
                'events' => [[
                    'key' => 'payment_due_reminder',
                    'is_active' => false,       // must be ignored: a mandatory event can't be turned off
                    'channel_email' => false,   // channels ARE still editable
                    'channel_push' => true,
                    'channel_whatsapp' => false,
                    'board_role_ids' => [],
                ]],
            ])
            ->assertOk();

        $tenant->run(function () use ($event): void {
            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();

            // Backend enforcement: is_active stays true despite the client sending false.
            $this->assertTrue($setting->is_active, 'A mandatory event must not be disable-able via PUT.');
            // Channel toggles remain honored (only the on/off is locked).
            $this->assertTrue($setting->channel_push, 'Channel toggles must still be honored for mandatory events.');
            $this->assertFalse($setting->channel_email);

            // resolve() is defensive even if a stored row somehow says false.
            $setting->forceFill(['is_active' => false])->saveQuietly();
            $resolved = app(NotificationSettingService::class)->resolve('payment_due_reminder');
            $this->assertTrue($resolved['is_active'], 'resolve() must force is_active=true for a mandatory event.');
        });

        // GET payload surfaces is_mandatory + the forced-on is_active.
        $events = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notifications')
            ->assertOk()
            ->json('data.events');

        $row = collect($events)->firstWhere('key', 'payment_due_reminder');
        $this->assertNotNull($row);
        $this->assertTrue($row['is_mandatory'], 'GET payload must include is_mandatory=true.');
        $this->assertTrue($row['is_active'], 'allForTenant must force is_active=true for mandatory events.');
    }

    // =========================================================================
    // is_hidden enforcement (system events excluded from config, still fire)
    // =========================================================================

    public function test_hidden_event_is_excluded_from_config_but_still_fires(): void
    {
        (new CatalogSeeder)->run();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // trial_expired is a hidden system event: it must never appear in the config payload...
        $events = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notifications')
            ->assertOk()
            ->json('data.events');

        $keys = collect($events)->pluck('key')->all();
        $this->assertNotContains('trial_expired', $keys, 'Hidden event trial_expired must be excluded from the config payload.');

        // ...but it must keep firing: resolve() forces is_active=true for a hidden event,
        // even if its (never-shown) setting row is stored inactive.
        $tenant->run(function (): void {
            (new TenantNotificationSettingSeeder)->run();

            $event = NotificationEvent::findByKey('trial_expired');
            NotificationSetting::where('notification_event_id', $event->id)->update(['is_active' => false]);

            $resolved = app(NotificationSettingService::class)->resolve('trial_expired');
            $this->assertTrue($resolved['is_active'], 'Hidden event must still resolve is_active=true so it keeps firing.');
        });
    }
}
