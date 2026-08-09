<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\Role;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Database\Seeders\TenantNotificationSettingSeeder;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationUser;
use Modules\Notification\Services\NotificationSettingService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Phase 2 — NotificationSettingService + notifyEvent() + boardRecipientsForEvent().
 *
 * Covers:
 *   (a) resolve() maps channel bools on a NotificationSetting to the correct
 *       dispatcher channel names, always prepending 'log', and returns the
 *       configured board_role_ids.
 *   (b) resolve() falls back to the event's default_channels when no
 *       NotificationSetting row exists for the current tenant.
 *   (c) notifyEvent() with is_active=false returns immediately — no
 *       NotificationUser rows are created. This is a synchronous early-exit
 *       check and works correctly under transaction-wrapped tests.
 *   (d) boardRecipientsForEvent('payment_registered') resolves the user ids of
 *       Treasurer and President members in the current tenant.
 *
 * Standard RefreshDatabase (transaction-wrapped) is used throughout. The
 * is_active=false gate is a synchronous check that never reaches DB::afterCommit,
 * so asserting NotificationUser row counts is valid here without opting out of
 * transaction wrapping.
 */
final class NotificationSettingServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        $this->seedGlobalRoles();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedGlobalRoles(): void
    {
        foreach (['president', 'treasurer', 'secretary', 'administrator', 'resident', 'renter', 'security'] as $slug) {
            Role::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser(?string $roleSlug = null): User
    {
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User '.Str::random(4),
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('secret'),
            'auth_active' => true,
        ]);

        if ($roleSlug !== null) {
            $user->assignRole($roleSlug);
        }

        return $user;
    }

    /**
     * Create a concrete stub that exposes protected NotifiesResidents methods for testing.
     */
    private function makeNotifier(): object
    {
        return new class
        {
            use NotifiesResidents;

            public function callNotifyEvent(
                string $eventKey,
                array $recipientUserIds,
                array $context,
                string $fallbackTitle,
                string $fallbackBody,
                array $data = [],
            ): void {
                $this->notifyEvent($eventKey, $recipientUserIds, $context, $fallbackTitle, $fallbackBody, $data);
            }

            public function callBoardRecipientsForEvent(string $eventKey): array
            {
                return $this->boardRecipientsForEvent($eventKey);
            }
        };
    }

    // ── (a) resolve() maps channel bools → dispatcher names ──────────────────

    public function test_resolve_maps_all_channel_bools_to_dispatcher_names_and_always_includes_log(): void
    {
        $tenant = $this->makeTenant('Channel Mapping Cluster');

        $tenant->run(function (): void {
            (new TenantNotificationSettingSeeder)->run();

            $event = NotificationEvent::findByKey('payment_due_reminder');
            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();

            // Enable all three delivery channels
            $setting->update([
                'channel_email' => true,
                'channel_push' => true,
                'channel_whatsapp' => true,
            ]);

            $resolved = app(NotificationSettingService::class)->resolve('payment_due_reminder');

            $this->assertTrue($resolved['is_active']);

            // 'log' must always be the first channel
            $this->assertEquals('log', $resolved['channels'][0], "'log' (in-app) must always be first");

            $this->assertContains('blade_email', $resolved['channels'], 'channel_email=true must resolve to blade_email');
            $this->assertContains('firebase_push', $resolved['channels'], 'channel_push=true must resolve to firebase_push');
            $this->assertContains('whatsapp', $resolved['channels'], 'channel_whatsapp=true must resolve to whatsapp');
        });
    }

    public function test_resolve_excludes_disabled_delivery_channels(): void
    {
        $tenant = $this->makeTenant('Partial Channel Cluster');

        $tenant->run(function (): void {
            (new TenantNotificationSettingSeeder)->run();

            $event = NotificationEvent::findByKey('payment_due_reminder');
            $setting = NotificationSetting::where('notification_event_id', $event->id)->firstOrFail();

            // Only email enabled; push + whatsapp disabled
            $setting->update([
                'channel_email' => true,
                'channel_push' => false,
                'channel_whatsapp' => false,
            ]);

            $resolved = app(NotificationSettingService::class)->resolve('payment_due_reminder');

            $this->assertContains('log', $resolved['channels']);
            $this->assertContains('blade_email', $resolved['channels']);
            $this->assertNotContains('firebase_push', $resolved['channels'], 'Disabled push must not appear');
            $this->assertNotContains('whatsapp', $resolved['channels'], 'Disabled whatsapp must not appear');
        });
    }

    public function test_resolve_returns_configured_board_role_ids(): void
    {
        $tenant = $this->makeTenant('Board Role IDs Cluster');

        $tenant->run(function (): void {
            (new TenantNotificationSettingSeeder)->run();

            $resolved = app(NotificationSettingService::class)->resolve('payment_registered');

            // TenantNotificationSettingSeeder seeds Treasurer + President by default
            $this->assertContains(BoardRole::TREASURER, $resolved['board_role_ids'], 'Treasurer must be in board_role_ids');
            $this->assertContains(BoardRole::PRESIDENT, $resolved['board_role_ids'], 'President must be in board_role_ids');
            $this->assertCount(2, $resolved['board_role_ids']);
        });
    }

    // ── (b) Fallback to event default_channels when no setting row ────────────

    public function test_resolve_falls_back_to_event_defaults_when_no_setting_row_exists(): void
    {
        $tenant = $this->makeTenant('No Setting Row Cluster');

        // Do NOT run TenantNotificationSettingSeeder — no setting rows exist
        $tenant->run(function (): void {
            $this->assertEquals(0, NotificationSetting::count(), 'No settings must exist before fallback test');

            $event = NotificationEvent::findByKey('payment_due_reminder');

            $resolved = app(NotificationSettingService::class)->resolve('payment_due_reminder');

            $this->assertTrue($resolved['is_active'], 'Fallback must default to is_active=true');
            $this->assertContains('log', $resolved['channels'], "'log' must always be present");
            $this->assertEmpty($resolved['board_role_ids'], 'Fallback must return no board_role_ids');

            // Every channel from event defaults must appear in the resolved list
            foreach ((array) $event->default_channels as $defaultChannel) {
                $this->assertContains(
                    $defaultChannel,
                    $resolved['channels'],
                    "Default channel '{$defaultChannel}' must appear when falling back to event defaults"
                );
            }
        });
    }

    public function test_resolve_returns_inactive_and_log_only_for_unknown_event_key(): void
    {
        $tenant = $this->makeTenant('Unknown Key Cluster');

        $tenant->run(function (): void {
            $resolved = app(NotificationSettingService::class)->resolve('totally_unknown_event');

            $this->assertFalse($resolved['is_active'], 'Unknown event key must resolve to is_active=false');
            $this->assertEquals(['log'], $resolved['channels'], 'Unknown event key must resolve to only the log channel');
            $this->assertEmpty($resolved['board_role_ids']);
        });
    }

    public function test_resolve_returns_event_defaults_without_querying_tenant_table_when_tenancy_is_not_initialized(): void
    {
        // Tenancy is never initialized outside of $tenant->run() — this test
        // deliberately calls resolve() without one to trigger the guard.
        $this->assertFalse(tenancy()->initialized, 'Tenancy must not be initialized for this test.');

        $event = NotificationEvent::findByKey('payment_due_reminder');
        $this->assertNotNull($event);

        $resolved = app(NotificationSettingService::class)->resolve('payment_due_reminder');

        // Guard returns event defaults: is_active=true, no board roles.
        $this->assertTrue($resolved['is_active'], 'Without tenant context, is_active must default to true');
        $this->assertContains('log', $resolved['channels'], "'log' must always be present");
        $this->assertEmpty($resolved['board_role_ids'], 'No board_role_ids when tenant context is missing');

        // Every default channel from the central event must appear in the resolved list.
        foreach ((array) $event->default_channels as $defaultChannel) {
            $this->assertContains(
                $defaultChannel,
                $resolved['channels'],
                "Default channel '{$defaultChannel}' must appear when falling back without tenant context"
            );
        }
    }

    // ── (c) notifyEvent with is_active=false dispatches nothing ──────────────

    public function test_notify_event_returns_early_without_creating_rows_when_event_is_inactive(): void
    {
        $tenant = $this->makeTenant('Inactive Event Cluster');
        $user = $this->makeUser();
        $user->tenants()->attach($tenant->id);

        $tenant->run(function () use ($user): void {
            (new TenantNotificationSettingSeeder)->run();

            // Deactivate the event
            $event = NotificationEvent::findByKey('payment_due_reminder');
            NotificationSetting::where('notification_event_id', $event->id)
                ->update(['is_active' => false]);

            $initialCount = NotificationUser::count();

            // callNotifyEvent checks is_active synchronously and returns early;
            // no recordInApp() or DB::afterCommit() is ever reached.
            $notifier = $this->makeNotifier();
            $notifier->callNotifyEvent(
                'payment_due_reminder',
                [$user->id],
                ['concept' => 'Mantenimiento', 'amount' => '$1,500.00', 'due_date' => '2026-07-01'],
                'Recordatorio de pago',
                'Tu pago vence pronto.',
            );

            $this->assertEquals(
                $initialCount,
                NotificationUser::count(),
                'notifyEvent must create zero NotificationUser rows when is_active=false'
            );
        });
    }

    // ── (d) boardRecipientsForEvent resolves Treasurer + President ────────────

    public function test_board_recipients_for_event_resolves_treasurer_and_president_user_ids(): void
    {
        $tenant = $this->makeTenant('Board Recipients Cluster');

        $president = $this->makeUser('president');
        $treasurer = $this->makeUser('treasurer');
        $resident = $this->makeUser('resident'); // must NOT appear

        $president->tenants()->attach($tenant->id);
        $treasurer->tenants()->attach($tenant->id);
        $resident->tenants()->attach($tenant->id);

        $tenant->run(function () use ($president, $treasurer, $resident): void {
            (new TenantNotificationSettingSeeder)->run();

            $recipientIds = ($this->makeNotifier())->callBoardRecipientsForEvent('payment_registered');

            $this->assertContains($president->id, $recipientIds, 'President must be a recipient of payment_registered');
            $this->assertContains($treasurer->id, $recipientIds, 'Treasurer must be a recipient of payment_registered');
            $this->assertNotContains($resident->id, $recipientIds, 'Resident must not be a recipient of a board event');
        });
    }

    public function test_board_recipients_for_event_returns_empty_for_resident_audience_events(): void
    {
        $tenant = $this->makeTenant('Resident Audience Cluster');

        $tenant->run(function (): void {
            (new TenantNotificationSettingSeeder)->run();

            // payment_due_reminder is resident-audience; board_role_ids is empty
            $recipientIds = ($this->makeNotifier())->callBoardRecipientsForEvent('payment_due_reminder');

            $this->assertEmpty($recipientIds, 'Resident-audience events must return no board recipients');
        });
    }

    public function test_board_recipients_are_scoped_to_current_tenant(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        // President belongs only to tenant A
        $presidentA = $this->makeUser('president');
        $presidentA->tenants()->attach($tenantA->id);

        // Treasurer belongs to both tenants
        $treasurerBoth = $this->makeUser('treasurer');
        $treasurerBoth->tenants()->attach($tenantA->id);
        $treasurerBoth->tenants()->attach($tenantB->id);

        $tenantA->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        $tenantB->run(static function (): void {
            (new TenantNotificationSettingSeeder)->run();
        });

        // Under tenant A: both president and treasurer resolve
        $recipientsA = [];
        $tenantA->run(function () use (&$recipientsA): void {
            $recipientsA = ($this->makeNotifier())->callBoardRecipientsForEvent('payment_registered');
        });

        $this->assertContains($presidentA->id, $recipientsA, 'President of tenant A must appear under tenant A');
        $this->assertContains($treasurerBoth->id, $recipientsA, 'Treasurer (tenant A member) must appear under tenant A');

        // Under tenant B: president of tenant A must not appear
        $recipientsB = [];
        $tenantB->run(function () use (&$recipientsB): void {
            $recipientsB = ($this->makeNotifier())->callBoardRecipientsForEvent('payment_registered');
        });

        $this->assertNotContains($presidentA->id, $recipientsB, 'President of tenant A must not appear under tenant B');
        $this->assertContains($treasurerBoth->id, $recipientsB, 'Treasurer (tenant B member) must appear under tenant B');
    }
}
