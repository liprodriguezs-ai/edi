<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationSettingBoardRole;

/**
 * Seeds per-cluster notification settings from the central NotificationEvent catalog.
 *
 * Runs inside a tenant context (TenantCreated → tenants:seed → TenantDatabaseSeeder)
 * so BelongsToTenant stamps tenant_id automatically. Idempotent: firstOrCreate
 * prevents duplicates on repeated runs.
 *
 * For each notification_event:
 *   - Creates one notification_setting row using default_channels from the event.
 *   - For payment_registered (audience=board): also seeds the default recipient
 *     board roles (Treasurer + President) in notification_setting_board_role.
 */
class TenantNotificationSettingSeeder extends Seeder
{
    public function run(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        /** @var NotificationEvent[] $events */
        $events = NotificationEvent::all();

        foreach ($events as $event) {
            $channels = $event->default_channels ?? [];

            $setting = NotificationSetting::firstOrCreate(
                ['notification_event_id' => $event->id],
                [
                    'is_active' => true,
                    'channel_email' => in_array('blade_email', $channels),
                    'channel_push' => in_array('firebase_push', $channels),
                    'channel_whatsapp' => in_array('whatsapp', $channels),
                ]
            );

            // Seed default board-role recipients for board-audience events.
            // Currently only payment_registered has default recipients (Treasurer + President).
            // Use the stable UUID constant — never the key string (project convention).
            if ($event->id === NotificationEvent::PAYMENT_REGISTERED) {
                $this->seedPaymentRegisteredBoardRoles($setting);
            }

            if ($event->id === NotificationEvent::NEW_BOARD) {
                $this->seedNewBoardBoardRoles($setting);
            }

            if ($event->id === NotificationEvent::AMENITY_RESERVATION_CREATED) {
                $this->seedAmenityReservationCreatedBoardRoles($setting);
            }
        }
    }

    /**
     * Create the default Treasurer + President recipient rows for the
     * payment_registered event if they do not already exist.
     *
     * Note: if a row is soft-deleted (admin manually removed a role),
     * firstOrCreate will not restore it — the admin's decision is respected.
     */
    private function seedPaymentRegisteredBoardRoles(NotificationSetting $setting): void
    {
        foreach ([BoardRole::TREASURER, BoardRole::PRESIDENT] as $boardRoleId) {
            NotificationSettingBoardRole::firstOrCreate([
                'notification_setting_id' => $setting->id,
                'board_role_id' => $boardRoleId,
            ]);
        }
    }

    /**
     * Default recipients for new_board (audience=board): the board leadership
     * (President + Secretary). Residents learn of a new board via the auto-created
     * cluster announcement, not this targeted event (ADR-4).
     */
    private function seedNewBoardBoardRoles(NotificationSetting $setting): void
    {
        foreach ([BoardRole::PRESIDENT, BoardRole::SECRETARY] as $boardRoleId) {
            NotificationSettingBoardRole::firstOrCreate([
                'notification_setting_id' => $setting->id,
                'board_role_id' => $boardRoleId,
            ]);
        }
    }

    /**
     * Default recipients for amenity_reservation.created (audience=board):
     * President + Treasurer, configurable via the board-role picker. The
     * cluster's 'administrator' Spatie role ALSO always receives this event —
     * that recipient is NOT a board_role catalog row (administrator isn't a
     * mesa directiva seat), so ReservationService merges it in directly via
     * userIdsWithRole('administrator') and it is never stored here.
     */
    private function seedAmenityReservationCreatedBoardRoles(NotificationSetting $setting): void
    {
        foreach ([BoardRole::PRESIDENT, BoardRole::TREASURER] as $boardRoleId) {
            NotificationSettingBoardRole::firstOrCreate([
                'notification_setting_id' => $setting->id,
                'board_role_id' => $boardRoleId,
            ]);
        }
    }
}
