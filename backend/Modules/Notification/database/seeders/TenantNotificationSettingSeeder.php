<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;

/**
 * Seeds per-tenant notification settings from the central NotificationEvent catalog.
 *
 * Runs inside a tenant context (TenantCreated → tenants:seed → TenantDatabaseSeeder)
 * so BelongsToTenant stamps tenant_id automatically. Idempotent: firstOrCreate
 * prevents duplicates on repeated runs.
 *
 * For each notification_event it creates one notification_setting row using the
 * event's default_channels.
 *
 * NOTE: recipient targeting by role is not modelled yet. The template shipped a
 * board-role picker (notification_setting_board_role) tied to the condominium's
 * mesa directiva, which does not exist in this domain. When EDI defines who
 * should receive each event, add the targeting back here.
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

            NotificationSetting::firstOrCreate(
                ['notification_event_id' => $event->id],
                [
                    'is_active' => true,
                    'channel_email' => in_array('blade_email', $channels),
                    'channel_push' => in_array('firebase_push', $channels),
                    'channel_whatsapp' => in_array('whatsapp', $channels),
                ]
            );
        }
    }
}
