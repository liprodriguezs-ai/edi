<?php

namespace App\Notifications\Services;

use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Notifications\Channels\BladeEmailChannel;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\Channels\LogChannel;
use App\Notifications\Channels\ResendEmailChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Notification\Jobs\DeliverChannelMessageJob;
use Modules\Tenant\Models\Tenant;

/**
 * NotificationDispatcher — resolves configured channel drivers and dispatches
 * notifications through all of them.
 *
 * Active channels are taken from config('notifications.channels'), defaulting
 * to ['log']. Additional channels can be plugged in without touching callers.
 *
 * ADDRESS RESOLUTION (§17.1): recipients are user ids (UUIDs). Before handing them
 * to a delivery channel, the dispatcher resolves each user's real address for that
 * channel — email for 'resend_email', phone for 'sms', token_sender (FCM device
 * token) for 'firebase_push'. Users missing the address are silently skipped for
 * that channel. Non-UUID recipients (raw emails/phones/tokens) pass through as-is.
 * The 'log' channel keeps user ids — it is the in-app/dev record.
 *
 * PREFERENCES (§17.3): when recipients are user ids, each delivery channel is
 * filtered against the recipient's NotificationPreference row. A user who disabled
 * a channel for the notification's category does not receive it through that channel.
 * The 'log' channel (in-app/dev record) is ALWAYS delivered and never filtered. When
 * a user has no preference row, every channel is allowed — so the default behavior is
 * unchanged. During a user's quiet hours, non-urgent push is suppressed (urgent push
 * still goes through); mark a notification urgent via $data['urgent'] === true.
 *
 * TENANT FAN-OUT: role-based recipient resolution MUST go through
 * tenantRoleUserIds()/toTenantRole(), which always intersect the Spatie role
 * (global, guard 'api') with tenant membership (user_tenant pivot) — never
 * notify a role across all tenants.
 */
class NotificationDispatcher
{
    /**
     * Max FCM device tokens per push job. FCM sendMulticast accepts up to 500
     * tokens per call, so push deliveries are batched in chunks of this size
     * (one DeliverChannelMessageJob per chunk) instead of one job per token.
     */
    private const PUSH_CHUNK_SIZE = 500;

    /**
     * Maps channel name → concrete implementation class.
     *
     * @var array<string, class-string<NotificationChannelInterface>>
     */
    private const CHANNEL_MAP = [
        'log' => LogChannel::class,
        'firebase_push' => FirebasePushChannel::class,
        'resend_email' => ResendEmailChannel::class,
        'blade_email' => BladeEmailChannel::class,
        'sms' => SmsChannel::class,
        'whatsapp' => WhatsAppChannel::class,
    ];

    /**
     * Maps a delivery channel name → the User attribute holding the real address.
     * The 'log' channel is intentionally absent: it receives user ids untouched.
     *
     * @var array<string, string>
     */
    private const CHANNEL_ADDRESS_MAP = [
        'firebase_push' => 'token_sender',
        'resend_email' => 'email',
        'blade_email' => 'email',
        'sms' => 'phone',
        'whatsapp' => 'phone',
    ];

    /**
     * Maps a delivery channel name → the preference channel toggle it honors.
     * The 'log' channel is intentionally absent: it is never filtered.
     *
     * @var array<string, string>
     */
    private const PREFERENCE_CHANNEL_MAP = [
        'firebase_push' => 'push',
        'resend_email' => 'email',
        'blade_email' => 'email',
        'sms' => 'sms',
        'whatsapp' => 'whatsapp',
    ];

    /**
     * Dispatch a notification through all configured channels.
     *
     * @param  string[]  $channelNames  Explicit channel list; if empty, uses config.
     * @param  array<string|int, mixed>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function dispatch(
        array $recipients,
        string $title,
        string $body,
        array $data = [],
        array $channelNames = []
    ): void {
        if (empty($channelNames)) {
            /** @var string[] $channelNames */
            $channelNames = config('notifications.channels', ['log']);
        }

        $preferences = $this->loadPreferences($recipients);
        $users = $this->loadRecipientUsers($recipients, $channelNames);
        $category = isset($data['category']) ? (string) $data['category'] : null;
        $urgent = ($data['urgent'] ?? false) === true;

        foreach ($channelNames as $name) {
            // Validate the channel name synchronously so an unknown channel fails fast
            // in the request — never silently swallowed by a queued worker.
            $this->assertChannelRegistered($name);

            $filtered = $this->filterRecipientsForChannel($name, $recipients, $preferences, $category, $urgent);
            $addressed = $this->resolveChannelAddresses($name, $filtered, $users);

            if ($addressed === []) {
                continue;
            }

            // Channels WITHOUT an external address (only 'log') do no network I/O and
            // back the instant in-app/dev record → send INLINE, never queued.
            if (! array_key_exists($name, self::CHANNEL_ADDRESS_MAP)) {
                $this->makeChannel($name)->send($addressed, $title, $body, $data);

                continue;
            }

            // PUSH: batch tokens into chunks of ≤500 (FCM sendMulticast limit),
            // ONE job per chunk. FirebasePushChannel logs per-token failures (never
            // throws), so a chunk retry only re-pushes on a transport error — safe.
            if ($name === 'firebase_push') {
                foreach (array_chunk($addressed, self::PUSH_CHUNK_SIZE) as $chunk) {
                    DeliverChannelMessageJob::dispatch($name, $chunk, $title, $body, $data);
                }

                continue;
            }

            // EMAIL/SMS/WhatsApp: queue ONE job per recipient with the address
            // already resolved. Per-recipient for privacy (each in its own To) and
            // isolated retries (a retry never re-sends to recipients that succeeded).
            foreach ($addressed as $recipient) {
                DeliverChannelMessageJob::dispatch($name, [$recipient], $title, $body, $data);
            }
        }
    }

    /**
     * Resolve the user ids that hold ANY of the given Spatie roles (guard 'api')
     * AND belong to the given tenant (user_tenant pivot). This is the canonical,
     * tenant-safe role fan-out resolver: Spatie roles are GLOBAL in this project,
     * so resolving recipients by role alone leaks across clusters.
     *
     * Unknown role names are ignored (Spatie's role() scope throws otherwise).
     *
     * @param  string|array<int, string>  $roleNames
     * @return array<int, string>
     */
    public function tenantRoleUserIds(Tenant|string $tenant, string|array $roleNames): array
    {
        $tenantKey = $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : $tenant;
        $roleNames = is_array($roleNames) ? $roleNames : [$roleNames];

        $existing = Role::query()
            ->whereIn('name', $roleNames)
            ->where('guard_name', 'api')
            ->pluck('name')
            ->all();

        if ($existing === []) {
            return [];
        }

        $tenantTable = Tenant::make()->getTable();

        return User::query()
            ->withoutGlobalScope(UserTenantScope::class)
            ->whereHas('tenants', static function (Builder $query) use ($tenantTable, $tenantKey): void {
                $query->where($tenantTable.'.id', $tenantKey);
            })
            ->role($existing, 'api')
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Dispatch a notification to every member of the given tenant holding any of
     * the given roles. Returns the resolved user ids so callers can record the
     * in-app rows (NotificationUser) for the same audience.
     *
     * @param  string|array<int, string>  $roleNames
     * @param  array<string, mixed>  $data
     * @param  string[]  $channelNames
     * @return array<int, string>
     */
    public function toTenantRole(
        Tenant|string $tenant,
        string|array $roleNames,
        string $title,
        string $body,
        array $data = [],
        array $channelNames = ['log'],
    ): array {
        $userIds = $this->tenantRoleUserIds($tenant, $roleNames);

        if ($userIds !== []) {
            $this->dispatch($userIds, $title, $body, $data, $channelNames);
        }

        return $userIds;
    }

    /**
     * Load preference rows keyed by user id for the recipient set. Only string
     * recipients (user ids / emails) are considered; non-string recipients (e.g.
     * push tokens) cannot be matched and are left unfiltered.
     *
     * @param  array<string|int, mixed>  $recipients
     * @return array<string, NotificationPreference>
     */
    private function loadPreferences(array $recipients): array
    {
        // Only UUID-shaped recipients are user ids that can carry a preference row.
        // Non-UUID recipients (emails, phone numbers, push tokens) are left unfiltered.
        $ids = array_values(array_filter(
            $recipients,
            static fn ($r): bool => is_string($r) && Str::isUuid($r),
        ));

        if ($ids === []) {
            return [];
        }

        return NotificationPreference::query()
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    /**
     * Filter a recipient list for a single channel according to per-user preferences.
     * The 'log' channel is never filtered. Recipients without a preference row keep
     * the everything-on default.
     *
     * @param  array<string|int, mixed>  $recipients
     * @param  array<string, NotificationPreference>  $preferences
     * @return array<int, mixed>
     */
    private function filterRecipientsForChannel(
        string $channelName,
        array $recipients,
        array $preferences,
        ?string $category,
        bool $urgent,
    ): array {
        if (! array_key_exists($channelName, self::PREFERENCE_CHANNEL_MAP)) {
            return array_values($recipients);
        }

        $preferenceChannel = self::PREFERENCE_CHANNEL_MAP[$channelName];

        $allowed = [];
        foreach ($recipients as $recipient) {
            if (! is_string($recipient) || ! isset($preferences[$recipient])) {
                $allowed[] = $recipient;

                continue;
            }

            $preference = $preferences[$recipient];

            if (! $preference->allowsChannel($preferenceChannel, $category)) {
                continue;
            }

            // Quiet hours suppress NON-urgent push only.
            if ($preferenceChannel === 'push' && ! $urgent && $preference->isWithinQuietHours()) {
                continue;
            }

            $allowed[] = $recipient;
        }

        return $allowed;
    }

    /**
     * Load the User rows for UUID recipients once per dispatch, only when at least
     * one requested channel needs real addresses. The recipient set was already
     * resolved upstream, so the lookup deliberately ignores the tenant scope.
     *
     * @param  array<string|int, mixed>  $recipients
     * @param  string[]  $channelNames
     * @return Collection<string, User>
     */
    private function loadRecipientUsers(array $recipients, array $channelNames): Collection
    {
        $needsAddresses = array_intersect($channelNames, array_keys(self::CHANNEL_ADDRESS_MAP)) !== [];

        $ids = array_values(array_filter(
            $recipients,
            static fn ($recipient): bool => is_string($recipient) && Str::isUuid($recipient),
        ));

        if (! $needsAddresses || $ids === []) {
            return new Collection;
        }

        return User::query()
            ->withoutGlobalScope(UserTenantScope::class)
            ->whereIn('id', $ids)
            ->get(['id', 'email', 'phone', 'token_sender'])
            ->keyBy('id')
            ->toBase();
    }

    /**
     * Replace UUID recipients with the user's real address for the channel
     * (email / phone / FCM token). Users without that address are skipped.
     * Non-UUID recipients are assumed to already be addresses and pass through.
     *
     * @param  array<string|int, mixed>  $recipients
     * @param  Collection<string, User>  $users
     * @return array<int, mixed>
     */
    private function resolveChannelAddresses(string $channelName, array $recipients, Collection $users): array
    {
        if (! array_key_exists($channelName, self::CHANNEL_ADDRESS_MAP)) {
            return $recipients;
        }

        $attribute = self::CHANNEL_ADDRESS_MAP[$channelName];

        $addresses = [];
        foreach ($recipients as $recipient) {
            if (! is_string($recipient) || ! Str::isUuid($recipient)) {
                $addresses[] = $recipient;

                continue;
            }

            $address = $users->get($recipient)?->getAttribute($attribute);

            if (is_string($address) && trim($address) !== '') {
                $addresses[] = trim($address);
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Resolve a channel driver by name. PUBLIC so the queued DeliverChannelMessageJob
     * can rebuild the channel on the worker from the registered CHANNEL_MAP (resolving
     * through the container so test spies bound to the channel class still apply).
     *
     * @throws InvalidArgumentException when the channel name is not registered.
     */
    public function makeChannel(string $name): NotificationChannelInterface
    {
        $this->assertChannelRegistered($name);

        return app(self::CHANNEL_MAP[$name]);
    }

    /**
     * @throws InvalidArgumentException when the channel name is not registered.
     */
    private function assertChannelRegistered(string $name): void
    {
        if (! array_key_exists($name, self::CHANNEL_MAP)) {
            throw new InvalidArgumentException(
                "Notification channel '{$name}' is not registered. Available: ".implode(', ', array_keys(self::CHANNEL_MAP))
            );
        }
    }
}
