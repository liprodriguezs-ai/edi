<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;

/**
 * NotificationSettingService — resolves per-tenant notification configuration.
 *
 * Given an event key, returns whether the event is active for the current tenant
 * and which delivery channel names to pass to NotificationDispatcher.
 *
 * MUST be called within an initialized tenant context so that BelongsToTenant
 * scoping applies to NotificationSetting queries. When no tenant context exists
 * the method falls back to the central event's default_channels instead of
 * querying the tenant-scoped table (which would bypass TenantScope and risk
 * leaking rows from other tenants). If no setting row exists for the current
 * tenant, the same event defaults are used.
 */
class NotificationSettingService
{
    /**
     * Resolve notification configuration for the given event key in the current tenant.
     *
     * NotificationEvent is a central catalog (safe without tenant context).
     * NotificationSetting is tenant-scoped — only queried after confirming
     * that tenancy is initialized.
     *
     * @return array{is_active: bool, channels: string[]}
     */
    public function resolve(string $eventKey): array
    {
        $event = NotificationEvent::findByKey($eventKey);

        if ($event === null) {
            Log::warning('[NotificationSettingService] Unknown event key — notification suppressed', [
                'key' => $eventKey,
            ]);

            return [
                'is_active' => false,
                'channels' => ['log'],
            ];
        }

        // Guard: NotificationSetting is BelongsToTenant. Querying it without an
        // initialized tenant bypasses TenantScope and would expose rows from every
        // cluster. Return the central event's defaults instead — is_active=true so
        // the notification is never silently suppressed due to a missing context.
        if (! tenancy()->initialized || ! tenant()) {
            Log::warning('[NotificationSettingService] resolve() called without an initialized tenant — returning event defaults.', [
                'event' => $eventKey,
            ]);

            return [
                'is_active' => true,
                'channels' => $this->channelsFromDefaults((array) $event->default_channels),
            ];
        }

        $setting = NotificationSetting::where('notification_event_id', $event->id)
            ->first();

        if ($setting === null) {
            // No per-tenant row yet — use the event's factory defaults.
            return [
                'is_active' => true,
                'channels' => $this->channelsFromDefaults((array) $event->default_channels),
            ];
        }

        return [
            'is_active' => ($event->is_mandatory || $event->is_hidden) ? true : $setting->is_active,
            'channels' => $this->channelsFromSetting($setting),
        ];
    }

    // ── Config API helpers ────────────────────────────────────────────────────

    /**
     * Return all notification events LEFT-merged with the current tenant's
     * per-event settings.
     *
     * Must be called inside an initialized tenant context so TenantScope
     * applies to NotificationSetting queries.
     *
     * @return array{events: array<int, array<string, mixed>>}
     */
    public function allForTenant(): array
    {
        // Load all central events ordered for display, excluding hidden system events
        // (e.g. trial_expired) that the tenant admin must never see or configure.
        $events = NotificationEvent::where('is_hidden', false)->orderBy('order')->get();

        // Load this tenant's existing settings keyed by notification_event_id.
        /** @var Collection<string, NotificationSetting> $settingsMap */
        $settingsMap = NotificationSetting::query()
            ->get()
            ->keyBy('notification_event_id');

        $payload = $events->map(function (NotificationEvent $event) use ($settingsMap): array {
            $setting = $settingsMap->get($event->id);

            // Fall back to the central event defaults when no tenant row exists.
            $isActive = $event->is_mandatory ? true : ($setting !== null ? (bool) $setting->is_active : true);
            $channelEmail = $setting !== null
                ? (bool) $setting->channel_email
                : in_array('blade_email', (array) $event->default_channels, true);
            $channelPush = $setting !== null
                ? (bool) $setting->channel_push
                : in_array('firebase_push', (array) $event->default_channels, true);
            $channelWhatsapp = $setting !== null
                ? (bool) $setting->channel_whatsapp
                : in_array('whatsapp', (array) $event->default_channels, true);

            return [
                'key' => $event->key,
                'name' => $event->name,
                'description' => $event->description,
                'audience' => $event->audience,
                'is_mandatory' => (bool) $event->is_mandatory,
                'supports_email' => (bool) $event->supports_email,
                'supports_push' => (bool) $event->supports_push,
                'supports_whatsapp' => (bool) $event->supports_whatsapp,
                'is_active' => $isActive,
                'channel_email' => $channelEmail,
                'channel_push' => $channelPush,
                'channel_whatsapp' => $channelWhatsapp,
            ];
        })->values()->all();

        return [
            'events' => $payload,
        ];
    }

    /**
     * Upsert per-tenant notification settings from the PUT /config/notifications body.
     *
     * For each event entry, creates or updates the NotificationSetting row for the
     * current tenant.
     *
     * @param  array<int, array{key: string, is_active: bool, channel_email: bool, channel_push: bool, channel_whatsapp: bool}>  $events
     */
    public function updateForTenant(array $events): void
    {
        // Pre-load all central events keyed by key slug for fast lookup.
        $eventsByKey = NotificationEvent::all()->keyBy('key');

        DB::transaction(function () use ($events, $eventsByKey): void {
            foreach ($events as $entry) {
                $event = $eventsByKey->get($entry['key'] ?? '');

                if ($event === null) {
                    // Unknown key — skip silently (catalog may have been updated).
                    continue;
                }

                // Hidden system events (e.g. trial_expired) are not configurable by the
                // tenant admin — skip even if a key is smuggled into the request body.
                if ($event->is_hidden) {
                    continue;
                }

                $setting = NotificationSetting::firstOrNew([
                    'notification_event_id' => $event->id,
                ]);

                $setting->is_active = $event->is_mandatory ? true : (bool) ($entry['is_active'] ?? true);
                $setting->channel_email = (bool) ($entry['channel_email'] ?? false);
                $setting->channel_push = (bool) ($entry['channel_push'] ?? false);
                $setting->channel_whatsapp = (bool) ($entry['channel_whatsapp'] ?? false);
                $setting->save();

            }
        });
    }

    /**
     * Map NotificationSetting boolean columns to dispatcher channel names.
     *
     * Always includes 'log' (in-app record) first regardless of toggle state.
     *
     * @return string[]
     */
    private function channelsFromSetting(NotificationSetting $setting): array
    {
        $channels = ['log'];

        if ($setting->channel_email) {
            $channels[] = 'blade_email';
        }

        if ($setting->channel_push) {
            $channels[] = 'firebase_push';
        }

        if ($setting->channel_whatsapp) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    /**
     * Map the NotificationEvent's default_channels array to dispatcher channel names.
     *
     * Always includes 'log' first. Unknown or legacy channel names pass through
     * untouched so no default channel is silently dropped.
     *
     * @param  string[]  $defaults
     * @return string[]
     */
    private function channelsFromDefaults(array $defaults): array
    {
        $channels = ['log'];

        foreach ($defaults as $channel) {
            if ($channel !== 'log') {
                $channels[] = $channel;
            }
        }

        return array_values(array_unique($channels));
    }
}
