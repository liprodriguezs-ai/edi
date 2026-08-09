<?php

declare(strict_types=1);

namespace Modules\Notification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationSettingBoardRole;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S10 — idempotency + reversibility coverage for the one-shot data migration
 * 2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings
 * (task 1.3 / design ADR-3, §3.3).
 *
 * migrate:fresh (RefreshDatabase) already ran this migration's up() once
 * before every test method here, so a freshly-seeded tenant already has
 * channel_email=true on the 5 target rows (both from the migration AND from
 * the CatalogSeeder default_channels flip via TenantNotificationSettingSeeder
 * — task 1.2). To isolate and prove the MIGRATION's own up()/down() behavior
 * (not just the seeder default), each test first forces channel_email=false
 * on the 5 target rows — simulating the pre-migration state of an existing
 * tenant — then re-runs the migration's up()/down() logic directly.
 */
final class EnableAmenityReservationEmailsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private const TARGET_EVENT_IDS = [
        NotificationEvent::AMENITY_RESERVATION_CREATED,
        NotificationEvent::AMENITY_RESERVATION_APPROVED,
        NotificationEvent::AMENITY_RESERVATION_PAYMENT_CONFIRMED,
        NotificationEvent::AMENITY_RESERVATION_PAYMENT_REJECTED,
        NotificationEvent::AMENITY_RESERVATION_SURCHARGE_ADDED,
    ];

    /** A non-scope event that must stay untouched by the migration in either direction. */
    private const NON_SCOPE_EVENT_KEY = 'amenity_reservation.rejected';

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * Loads the anonymous migration instance directly from disk — the
     * standard technique for exercising a specific migration's up()/down()
     * outside the normal migrate/rollback batch flow.
     */
    private function migrationInstance(): object
    {
        return require base_path(
            'Modules/Notification/database/migrations/2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php'
        );
    }

    /** Must be called INSIDE the tenant context. */
    private function forceChannelEmail(bool $value): void
    {
        NotificationSetting::whereIn('notification_event_id', self::TARGET_EVENT_IDS)
            ->update(['channel_email' => $value]);
    }

    /** Must be called INSIDE the tenant context. */
    private function channelEmailValues(): array
    {
        return NotificationSetting::whereIn('notification_event_id', self::TARGET_EVENT_IDS)
            ->pluck('channel_email', 'notification_event_id')
            ->all();
    }

    private function seededTenant(): Tenant
    {
        $tenant = $this->makeTenant('MigrationTest '.uniqid());
        $this->seedTenant($tenant);

        return $tenant;
    }

    public function test_migration_flips_only_the_five_target_rows(): void
    {
        $tenant = $this->seededTenant();

        $this->inTenant($tenant, function (): void {
            $this->forceChannelEmail(false);

            $nonScopeEvent = NotificationEvent::findByKey(self::NON_SCOPE_EVENT_KEY);
            NotificationSetting::where('notification_event_id', $nonScopeEvent->id)
                ->update(['channel_email' => false]);
        });

        $tenant->run(function (): void {
            $this->migrationInstance()->up();
        });

        $this->inTenant($tenant, function (): void {
            foreach ($this->channelEmailValues() as $value) {
                $this->assertTrue((bool) $value);
            }

            $nonScopeEvent = NotificationEvent::findByKey(self::NON_SCOPE_EVENT_KEY);
            $nonScopeSetting = NotificationSetting::where('notification_event_id', $nonScopeEvent->id)->first();
            $this->assertFalse((bool) $nonScopeSetting->channel_email, 'Non-scope event must not be flipped by the migration.');
        });
    }

    public function test_migration_is_idempotent(): void
    {
        $tenant = $this->seededTenant();

        $this->inTenant($tenant, function (): void {
            $this->forceChannelEmail(false);
        });

        $tenant->run(function (): void {
            $this->migrationInstance()->up();
            // Re-running must not error and must leave the value unchanged (still true).
            $this->migrationInstance()->up();
        });

        $this->inTenant($tenant, function (): void {
            foreach ($this->channelEmailValues() as $value) {
                $this->assertTrue((bool) $value);
            }
        });
    }

    /**
     * S10 QA fix — a tenant created BEFORE the AmenityReservations module has
     * ZERO notification_setting rows for any amenity_reservation.* event
     * (those rows are only ever seeded on TenantCreated or when the admin
     * saves the config UI). A plain UPDATE is a no-op for such a tenant, so
     * board_role_ids resolves to [] at send time and President/Treasurer are
     * silently dropped from the recipients. This proves the migration
     * backfills the missing rows (not just flips existing ones).
     */
    public function test_migration_backfills_missing_amenity_settings_for_a_pre_amenity_tenant(): void
    {
        $tenant = $this->seededTenant();

        // Simulate a pre-S10 tenant: hard-delete every amenity_reservation.*
        // notification_setting row (cascades to notification_setting_board_role)
        // so none exist, mirroring a tenant that predates the module.
        $this->inTenant($tenant, function (): void {
            $amenityEventIds = NotificationEvent::where('key', 'like', 'amenity_reservation.%')->pluck('id');
            NotificationSetting::whereIn('notification_event_id', $amenityEventIds)->forceDelete();

            $this->assertSame(
                0,
                NotificationSetting::whereIn('notification_event_id', $amenityEventIds)->count(),
                'Setup must remove all amenity rows to simulate a pre-S10 tenant.'
            );
        });

        $tenant->run(function (): void {
            $this->migrationInstance()->up();
        });

        $this->inTenant($tenant, function (): void {
            $amenityEvents = NotificationEvent::where('key', 'like', 'amenity_reservation.%')->get();
            $this->assertCount(11, $amenityEvents, 'Sanity check: the catalog must have all 11 amenity_reservation.* events.');

            $settings = NotificationSetting::whereIn('notification_event_id', $amenityEvents->pluck('id'))
                ->get()
                ->keyBy('notification_event_id');
            $this->assertCount(11, $settings, 'Backfill must create a notification_setting row for every amenity_reservation.* event.');

            foreach (self::TARGET_EVENT_IDS as $eventId) {
                $this->assertTrue(
                    (bool) $settings[$eventId]->channel_email,
                    "Backfilled row for event {$eventId} must have channel_email=true."
                );
            }

            $createdSetting = $settings[NotificationEvent::AMENITY_RESERVATION_CREATED];
            $boardRoleIds = NotificationSettingBoardRole::where('notification_setting_id', $createdSetting->id)
                ->pluck('board_role_id')
                ->sort()
                ->values()
                ->all();
            $expectedBoardRoleIds = collect([BoardRole::PRESIDENT, BoardRole::TREASURER])->sort()->values()->all();
            $this->assertSame(
                $expectedBoardRoleIds,
                $boardRoleIds,
                'Backfill must seed the default President + Treasurer board-role recipients for amenity_reservation.created.'
            );
        });
    }

    public function test_migration_down_restores_the_off_state_on_exactly_the_five_rows(): void
    {
        $tenant = $this->seededTenant();

        $this->inTenant($tenant, function (): void {
            $this->forceChannelEmail(false);

            $nonScopeEvent = NotificationEvent::findByKey(self::NON_SCOPE_EVENT_KEY);
            NotificationSetting::where('notification_event_id', $nonScopeEvent->id)
                ->update(['channel_email' => true]);
        });

        $tenant->run(function (): void {
            $this->migrationInstance()->up();
            $this->migrationInstance()->down();
        });

        $this->inTenant($tenant, function (): void {
            foreach ($this->channelEmailValues() as $value) {
                $this->assertFalse((bool) $value);
            }

            $nonScopeEvent = NotificationEvent::findByKey(self::NON_SCOPE_EVENT_KEY);
            $nonScopeSetting = NotificationSetting::where('notification_event_id', $nonScopeEvent->id)->first();
            $this->assertTrue((bool) $nonScopeSetting->channel_email, 'down() must not touch a non-scope event.');
        });
    }
}
