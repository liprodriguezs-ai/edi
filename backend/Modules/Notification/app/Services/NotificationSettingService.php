<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationSettingBoardRole;

/**
 * NotificationSettingService — resolves per-tenant notification configuration.
 *
 * Given an event key, returns whether the event is active for the current tenant,
 * which delivery channel names to pass to NotificationDispatcher, and which board
 * role IDs (from notification_setting_board_role) should receive the event.
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
     * that tenancy is initialized, mirroring the guard in NotifiesResidents::userIdsWithRole().
     *
     * @return array{is_active: bool, channels: string[], board_role_ids: string[]}
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
                'board_role_ids' => [],
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
                'board_role_ids' => [],
            ];
        }

        $setting = NotificationSetting::where('notification_event_id', $event->id)
            ->with('board_roles')
            ->first();

        if ($setting === null) {
            // No per-tenant row yet — use the event's factory defaults.
            return [
                'is_active' => true,
                'channels' => $this->channelsFromDefaults((array) $event->default_channels),
                'board_role_ids' => [],
            ];
        }

        return [
            'is_active' => ($event->is_mandatory || $event->is_hidden) ? true : $setting->is_active,
            'channels' => $this->channelsFromSetting($setting),
            'board_role_ids' => $setting->board_roles
                ->pluck('board_role_id')
                ->values()
                ->all(),
        ];
    }

    // ── Config API helpers ────────────────────────────────────────────────────

    /**
     * Return all notification events LEFT-merged with the current tenant's
     * per-event settings plus the active board-role catalog.
     *
     * Must be called inside an initialized tenant context so TenantScope
     * applies to NotificationSetting queries.
     *
     * @return array{events: array<int, array<string, mixed>>, board_roles: array<int, array<string, mixed>>}
     */
    public function allForTenant(): array
    {
        // Load all central events ordered for display, excluding hidden system events
        // (e.g. trial_expired) that the tenant admin must never see or configure.
        $events = NotificationEvent::where('is_hidden', false)->orderBy('order')->get();

        // Load this tenant's existing settings keyed by notification_event_id.
        /** @var Collection<string, NotificationSetting> $settingsMap */
        $settingsMap = NotificationSetting::with('board_roles')
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

            $boardRoleIds = $setting !== null
                ? $setting->board_roles->pluck('board_role_id')->values()->all()
                : [];

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
                'board_role_ids' => $boardRoleIds,
            ];
        })->values()->all();

        // Only active board roles are relevant as options for the admin.
        $boardRoles = BoardRole::where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'name'])
            ->map(fn (BoardRole $r): array => ['id' => $r->id, 'name' => $r->name])
            ->values()
            ->all();

        return [
            'events' => $payload,
            'board_roles' => $boardRoles,
        ];
    }

    /**
     * Upsert per-tenant notification settings from the PUT /config/notifications body.
     *
     * For each event entry:
     *  - Creates or updates the NotificationSetting row for the current tenant.
     *  - Syncs notification_setting_board_role rows (only for audience=board events;
     *    board_role_ids is silently ignored for audience=resident events).
     *
     * @param  array<int, array{key: string, is_active: bool, channel_email: bool, channel_push: bool, channel_whatsapp: bool, board_role_ids: string[]}>  $events
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

                // Sync board role recipients — only meaningful for board-audience events.
                // Silently ignoring board_role_ids for resident-audience events avoids
                // storing orphaned pivots that would never be used.
                if ($event->audience === 'board') {
                    $incomingRoleIds = array_values(
                        array_filter((array) ($entry['board_role_ids'] ?? []), 'is_string')
                    );

                    // Delete removed roles.
                    NotificationSettingBoardRole::where('notification_setting_id', $setting->id)
                        ->whereNotIn('board_role_id', $incomingRoleIds)
                        ->delete();

                    // Insert new roles (skip duplicates).
                    $existingIds = NotificationSettingBoardRole::where('notification_setting_id', $setting->id)
                        ->pluck('board_role_id')
                        ->all();

                    foreach ($incomingRoleIds as $roleId) {
                        if (! in_array($roleId, $existingIds, true)) {
                            NotificationSettingBoardRole::create([
                                'notification_setting_id' => $setting->id,
                                'board_role_id' => $roleId,
                            ]);
                        }
                    }
                }
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
