<?php

namespace App\Notifications\Concerns;

use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Notification\Services\TemplateRenderer;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Notification\Services\NotificationSettingService;

/**
 * DispatchesNotifications — shared helper for sending notifications.
 *
 * Resolves recipients by role and fans out through the notification engine
 * (per-event channel config, in-app rows, push, user preferences).
 *
 * TENANT CONTEXT REQUIRED: userIdsWithRole() logs a warning and returns no
 * recipients outside a tenant context, so callers must run inside
 * $tenant->run() or after tenancy()->initialize().
 *
 * DISPATCH SAFETY: notifyEvent() defers via DB::afterCommit, so calling it
 * after a domain write (but outside a transaction) is always safe.
 *
 * The template also resolved condominium recipients (a unit's residents, its
 * billing owner, the sitting board). Those helpers went with their modules;
 * EDI adds its own recipient resolvers here.
 */
trait DispatchesNotifications
{


    /**
     * Resolve user ids holding a given Spatie role name (e.g. 'president', 'treasurer')
     * WITHIN the current tenant. Spatie roles are global in this project, so the
     * resolution always intersects the role with tenant membership (user_tenant) via
     * NotificationDispatcher::tenantRoleUserIds() — a 'security' user of another
     * cluster must never be a recipient here.
     *
     * Callers must run inside a tenant context (initializeTenancy or $tenant->run());
     * without one, NO recipients are resolved — never the whole platform.
     *
     * @param  string|array<int, string>  $roleNames
     * @return array<int, string>
     */
    protected function userIdsWithRole(string|array $roleNames): array
    {
        if (! tenancy()->initialized || ! tenant()) {
            Log::warning('[DispatchesNotifications] userIdsWithRole called without an initialized tenant — no recipients resolved.', [
                'roles' => is_array($roleNames) ? $roleNames : [$roleNames],
            ]);

            return [];
        }

        return app(NotificationDispatcher::class)
            ->tenantRoleUserIds((string) tenant()->getTenantKey(), $roleNames);
    }


    /**
     * Record an in-app Notification + NotificationUser rows AND route through the
     * dispatcher (log driver). Safe: never throws — failures are reported, not raised.
     *
     * @param  array<int, string>  $userIds
     * @param  array<string, mixed>  $data
     */
    protected function notifyUsers(
        array $userIds,
        string $title,
        string $body,
        array $data = [],
        bool $inApp = true,
        array $channels = ['log'],
        bool $augmentPush = true,
    ): void {
        $userIds = array_values(array_unique(array_filter($userIds)));

        // Only blanket-append global push for ad-hoc callers. When the channel list was
        // derived from NotificationSettingService (per-event config), $augmentPush is
        // false so the cluster's per-event channel_push=false toggle is honored — the
        // global config must not silently re-enable push the admin turned off.
        if ($augmentPush) {
            $channels = $this->withConfiguredPushChannel($channels);
        }

        $dispatch = function () use ($userIds, $title, $body, $data, $inApp, $channels): void {
            try {
                if ($inApp) {
                    $this->recordInApp($userIds, $title, $body, $data);
                }

                app(NotificationDispatcher::class)->dispatch($userIds, $title, $body, $data, $channels);
            } catch (\Throwable $e) {
                report($e);
            }
        };

        // Defer until the surrounding transaction commits so a notification error
        // can never roll back the core domain write. If there is no open transaction,
        // afterCommit runs the callback immediately.
        DB::afterCommit($dispatch);
    }

    /**
     * Notify users using a configurable notification template (§16.3.5).
     *
     * Renders the template identified by $slug, substituting {variables} from
     * $context. If the template is INACTIVE (§16.4 r4) the notification is
     * SUPPRESSED — nothing is recorded or dispatched. When no template row exists
     * for the slug, falls back to the literal $fallbackTitle/$fallbackBody so a
     * missing template never silences a notification.
     *
     * @param  array<int, string>  $userIds
     * @param  array<string, scalar|null>  $context  template variables (without braces)
     * @param  array<string, mixed>  $data  in-app/dispatch metadata (e.g. click_action)
     */
    protected function notifyUsersFromTemplate(
        array $userIds,
        string $slug,
        array $context,
        string $fallbackTitle,
        string $fallbackBody,
        array $data = [],
        bool $inApp = true,
        array $channels = ['log'],
        bool $augmentPush = true,
    ): void {
        $rendered = app(TemplateRenderer::class)->render($slug, $context);

        // §16.4 r4: a deactivated template suppresses its notification entirely.
        if ($rendered !== null && $rendered['is_active'] === false) {
            return;
        }

        $title = $rendered['title'] ?? $this->substituteFallback($fallbackTitle, $context);
        $body = $rendered['body'] ?? $this->substituteFallback($fallbackBody, $context);

        $this->notifyUsers($userIds, $title, $body, $data, $inApp, $channels, $augmentPush);
    }

    /**
     * Dispatch a notification driven by the cluster's per-event configuration.
     *
     * Resolves active state, channel names, and (for board-audience events) board
     * role recipients from NotificationSettingService, then delegates to
     * notifyUsersFromTemplate. If the event is inactive for the current cluster,
     * returns immediately without recording any in-app rows or dispatching.
     *
     * Pass $preResolved (output of NotificationSettingService::resolve()) to skip the
     * internal resolve() call — eliminates the double-resolve when the caller already
     * resolved to compute board recipients (arch W1).
     *
     * Pass $templateKey when the SAME event needs a DIFFERENT copy variant. The event
     * key stays authoritative for configuration (is_active, channels, audience, board
     * roles); $templateKey only selects which `notification_template.*` row — and which
     * `email::*` view — renders the message. Used by the consolidated catch-up path in
     * Collections, where one dispatch covers N periods and therefore needs plural copy
     * with different {tokens} than the single-period template. Without this the plural
     * path would render the singular template and leak its unfilled {due_date} token to
     * residents. Keep the variant seeded in NotificationTemplateSeeder so clusters can
     * customize it like any other template.
     *
     * @param  array<int, string>  $recipientUserIds
     * @param  array<string, scalar|null>  $context  template variables + email view data
     * @param  array<string, mixed>  $data  extra dispatch metadata (click_action, etc.)
     * @param  array<string, mixed>  $preResolved  optional pre-resolved settings (skips internal resolve)
     * @param  string|null  $templateKey  copy variant slug; defaults to $eventKey
     */
    protected function notifyEvent(
        string $eventKey,
        array $recipientUserIds,
        array $context,
        string $fallbackTitle,
        string $fallbackBody,
        array $data = [],
        array $preResolved = [],
        ?string $templateKey = null,
    ): void {
        $resolved = $preResolved !== [] ? $preResolved : app(NotificationSettingService::class)->resolve($eventKey);

        if (! $resolved['is_active']) {
            return;
        }

        $templateKey ??= $eventKey;

        // The email view follows the COPY variant, not the event: a future
        // charge-created.blade.php holds singular wording and must never render a
        // consolidated multi-period dispatch. A missing variant view falls back to
        // plain text built from the already-correct rendered title/body.
        $data['email_template'] = $templateKey;
        $data['email_context'] = $context;

        $this->notifyUsersFromTemplate(
            $recipientUserIds,
            'notification_template.'.$templateKey,
            $context,
            $fallbackTitle,
            $fallbackBody,
            $data,
            true,
            $resolved['channels'],
            // $resolved['channels'] already reflects the per-event channel_push toggle
            // (channelsFromSetting includes firebase_push iff channel_push=true), so do
            // NOT re-augment with the global push config — that is the §17.4 override bug.
            augmentPush: false,
        );
    }


    /**
     * §17.4: ad-hoc emitters get real push mirroring their in-app row. When ops
     * enable 'firebase_push' globally (config notifications.channels), append it to
     * the requested channel list so direct notifyUsers() callers gain push without
     * touching each call site. Per-user preferences and quiet hours still filter the
     * final delivery in the dispatcher.
     *
     * NOT applied to the per-event path (notifyEvent → augmentPush=false): there the
     * cluster's per-event channel_push toggle is authoritative, so the global config
     * must not re-enable push the admin disabled for that event.
     *
     * @param  string[]  $channels
     * @return string[]
     */
    private function withConfiguredPushChannel(array $channels): array
    {
        $configured = (array) config('notifications.channels', ['log']);

        if (in_array('firebase_push', $configured, true) && ! in_array('firebase_push', $channels, true)) {
            $channels[] = 'firebase_push';
        }

        return $channels;
    }

    /**
     * Substitute {variables} in a literal fallback string (no Setting lookup).
     *
     * @param  array<string, scalar|null>  $context
     */
    private function substituteFallback(string $text, array $context): string
    {
        return app(TemplateRenderer::class)->substitute($text, $context);
    }

    /**
     * Create the in-app Notification row + one NotificationUser row per recipient.
     *
     * Fan-out is a SINGLE bulk insert (1 INSERT for N recipients) instead of N
     * firstOrCreate round-trips. The bulk insert bypasses model events, so
     * tenant_id, id (UUID) and timestamps are stamped by hand; insertOrIgnore
     * respects the partial unique (notification_id, user_id) WHERE deleted_at IS
     * NULL, preserving the firstOrCreate dedup semantics.
     *
     * @param  array<int, string>  $userIds
     * @param  array<string, mixed>  $data
     */
    private function recordInApp(array $userIds, string $title, string $body, array $data): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return;
        }

        $notification = Notification::create([
            'title' => $title,
            'message' => mb_substr($body, 0, 250),
            'status' => 'sent',
            'click_action' => $data['click_action'] ?? null,
            'repeat' => false,
            'date' => now()->toDateString(),
        ]);

        $now = now();
        $tenantId = tenant()?->getTenantKey();

        $rows = array_map(static fn (string $userId): array => [
            'id' => (string) Str::uuid(),
            'notification_id' => $notification->id,
            'user_id' => $userId,
            'is_read' => false,
            'tenant_id' => $tenantId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        NotificationUser::insertOrIgnore($rows);
    }
}
