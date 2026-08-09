<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Notifications\NotificationCategory;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Database\Seeders\TenantNotificationSettingSeeder;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationSettingBoardRole;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Verifies Phase 1 seeding behavior for the configurable notification system.
 *
 * Covers:
 *   (a) Central CatalogSeeder creates all expected notification_event rows
 *       with correct keys and audience values.
 *   (b) TenantNotificationSettingSeeder creates one notification_setting per
 *       event, scoped to the correct tenant.
 *   (c) Tenant isolation: settings of tenant A are invisible under tenant B.
 *   (d) payment_registered gets Treasurer + President pivot rows by default.
 *   (e) Idempotency: running the seeder twice creates no duplicate rows.
 */
class NotificationSettingSeedingTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed central catalogs once (includes board_role + notification_event).
        (new CatalogSeeder)->run();
    }

    // ── (a) Central seeding ───────────────────────────────────────────────────

    public function test_central_seeding_creates_all_notification_event_rows(): void
    {
        $events = NotificationEvent::all();

        // Updated 2026-07-19 (Complaints module — complaint_* events, +4; after
        // 2026-07-07 S10 amenity_reservation.*, +11) — this count must be bumped
        // whenever CatalogSeeder::seedNotificationEvents() grows.
        $this->assertCount(26, $events, 'CatalogSeeder must create exactly 26 notification_event rows.');

        $keys = $events->pluck('key')->sort()->values()->toArray();

        $this->assertEquals([
            'amenity_reservation.approved',
            'amenity_reservation.cancelled',
            'amenity_reservation.created',
            'amenity_reservation.deposit_confirmed',
            'amenity_reservation.expired',
            'amenity_reservation.payment_confirmed',
            'amenity_reservation.payment_rejected',
            'amenity_reservation.payment_reminder',
            'amenity_reservation.payment_required',
            'amenity_reservation.rejected',
            'amenity_reservation.surcharge_added',
            'charge_created',
            'charge_overdue',
            'complaint_assigned',
            'complaint_created',
            'complaint_message',
            'complaint_status_changed',
            'new_board',
            'payment_accepted',
            'payment_due_reminder',
            'payment_registered',
            'payment_registered_direct',
            'payment_rejected',
            'project_contribution_reminder',
            'trial_expired',
            'visitor_at_gate',
        ], $keys);

        // Phase 0 introduces the is_mandatory flag; no current event is critical yet.
        $this->assertTrue(
            $events->every(fn (NotificationEvent $e): bool => $e->is_mandatory === false),
            'All Phase 0 notification events must be is_mandatory=false.'
        );
    }

    public function test_notification_event_audience_values_are_correct(): void
    {
        $residentEvents = [
            'payment_due_reminder',
            'payment_accepted',
            'payment_rejected',
            'payment_registered_direct',
            'charge_overdue',
            'charge_created',
            'visitor_at_gate',
        ];

        foreach ($residentEvents as $key) {
            $event = NotificationEvent::findByKey($key);
            $this->assertNotNull($event, "Event '{$key}' must exist after CatalogSeeder.");
            $this->assertEquals('resident', $event->audience, "Event '{$key}' must have audience=resident.");
        }

        foreach (['payment_registered', 'new_board'] as $key) {
            $event = NotificationEvent::findByKey($key);
            $this->assertNotNull($event, "Event '{$key}' must exist after CatalogSeeder.");
            $this->assertEquals('board', $event->audience, "Event '{$key}' must have audience=board.");
        }
    }

    public function test_notification_event_categories_match_notification_category_constants(): void
    {
        // Collections events
        foreach (['payment_due_reminder', 'payment_registered', 'payment_accepted', 'payment_rejected', 'charge_overdue', 'charge_created'] as $key) {
            $event = NotificationEvent::findByKey($key);
            $this->assertEquals(NotificationCategory::COLLECTIONS, $event->category, "Event '{$key}' must have category=collections.");
        }

        $this->assertEquals(NotificationCategory::ACCESS, NotificationEvent::findByKey('visitor_at_gate')->category);
        $this->assertEquals(NotificationCategory::SYSTEM, NotificationEvent::findByKey('new_board')->category);
    }

    public function test_notification_event_default_channels_are_stored_as_array(): void
    {
        $event = NotificationEvent::findByKey('payment_due_reminder');

        $this->assertIsArray($event->default_channels, 'default_channels must be cast to array.');
        $this->assertNotEmpty($event->default_channels);
    }

    // ── (b) Per-tenant seeding ────────────────────────────────────────────────

    public function test_per_tenant_seeding_creates_one_setting_per_notification_event(): void
    {
        $tenant = $this->makeTenant('Seeding Test Cluster');

        $tenant->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        $eventCount = NotificationEvent::count();

        $settingCount = $this->inTenant($tenant, static fn (): int => NotificationSetting::count());

        $this->assertEquals(
            $eventCount,
            $settingCount,
            'One notification_setting must be created per notification_event.'
        );
    }

    public function test_per_tenant_settings_have_channels_derived_from_default_channels(): void
    {
        $tenant = $this->makeTenant('Channel Defaults Cluster');

        $tenant->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        $tenant->run(static function (): void {
            // payment_due_reminder has ['log', 'firebase_push', 'blade_email'] as defaults
            $event = NotificationEvent::findByKey('payment_due_reminder');
            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();

            self::assertTrue($setting->channel_push, 'payment_due_reminder must have push enabled.');
            self::assertTrue($setting->channel_email, 'payment_due_reminder must have email enabled.');
            self::assertFalse($setting->channel_whatsapp, 'payment_due_reminder must have whatsapp disabled by default.');
            self::assertTrue($setting->is_active, 'Settings must be active by default.');
        });
    }

    // ── (c) Tenant isolation ──────────────────────────────────────────────────

    public function test_tenant_a_settings_are_invisible_under_tenant_b_before_seeding(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $tenantA->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        // Tenant B has not been seeded — must see zero settings.
        $countUnderB = $this->inTenant($tenantB, static fn (): int => NotificationSetting::count());

        $this->assertEquals(0, $countUnderB, 'Tenant B must not see tenant A\'s notification settings before its own seeding.');
    }

    public function test_each_tenant_sees_only_its_own_settings_after_both_are_seeded(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $tenantA->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });
        $tenantB->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        $countA = $this->inTenant($tenantA, static fn (): int => NotificationSetting::count());
        $countB = $this->inTenant($tenantB, static fn (): int => NotificationSetting::count());

        $this->assertEquals($countA, $countB, 'Both tenants must have the same number of settings (one per event).');
        $this->assertGreaterThan(0, $countA);

        // Verify tenant_id is scoped correctly on each setting.
        $tenantA->run(function () use ($tenantA): void {
            NotificationSetting::all()->each(function (NotificationSetting $setting) use ($tenantA): void {
                $this->assertEquals($tenantA->id, $setting->tenant_id, 'Every setting under tenant A must carry tenant A\'s tenant_id.');
            });
        });
    }

    // ── (d) payment_registered board role pivots ──────────────────────────────

    public function test_payment_registered_gets_treasurer_and_president_board_role_pivots_by_default(): void
    {
        $tenant = $this->makeTenant('Board Pivot Test Cluster');

        $tenant->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        $tenant->run(function (): void {
            $event = NotificationEvent::findByKey('payment_registered');
            $this->assertNotNull($event);

            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();

            $pivots = NotificationSettingBoardRole::where('notification_setting_id', $setting->id)->get();

            $this->assertCount(2, $pivots, 'payment_registered must have exactly 2 default board role recipients.');

            $boardRoleIds = $pivots->pluck('board_role_id')->toArray();
            $this->assertContains(BoardRole::TREASURER, $boardRoleIds, 'Treasurer must be a default recipient of payment_registered.');
            $this->assertContains(BoardRole::PRESIDENT, $boardRoleIds, 'President must be a default recipient of payment_registered.');
        });
    }

    public function test_other_events_have_no_board_role_pivots_by_default(): void
    {
        $tenant = $this->makeTenant('No Board Pivot Cluster');

        $tenant->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        $tenant->run(function (): void {
            // payment_due_reminder is resident-audience — no pivot rows expected.
            $event = NotificationEvent::findByKey('payment_due_reminder');
            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();

            $pivotCount = NotificationSettingBoardRole::where('notification_setting_id', $setting->id)->count();

            $this->assertEquals(0, $pivotCount, 'Resident-audience events must not generate board role pivot rows.');
        });
    }

    // ── (e) Idempotency ───────────────────────────────────────────────────────

    public function test_per_tenant_seeding_is_idempotent_when_run_twice(): void
    {
        $tenant = $this->makeTenant('Idempotent Cluster');

        $tenant->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
            (new TenantNotificationSettingSeeder)->run();
        });

        $eventCount = NotificationEvent::count();
        $settingCount = $this->inTenant($tenant, static fn (): int => NotificationSetting::count());

        $this->assertEquals($eventCount, $settingCount, 'Running the seeder twice must not duplicate notification_setting rows.');

        $tenant->run(function (): void {
            $event = NotificationEvent::findByKey('payment_registered');
            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();
            $pivotCount = NotificationSettingBoardRole::where('notification_setting_id', $setting->id)->count();

            $this->assertEquals(2, $pivotCount, 'Running the seeder twice must not duplicate board role pivot rows.');
        });
    }
}
