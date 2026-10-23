<?php

namespace App\Models;

use App\Models\Scopes\UserTenantScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Support\TenantTime;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * NotificationPreference — a user's notification delivery preferences.
 *
 * Central model (no tenant scope, one row per user): preferences belong to the USER,
 * not the organization.
 *
 * `channels` is a JSON map of category => ['push'=>bool, 'email'=>bool, 'sms'=>bool].
 * A missing category or missing channel key means ENABLED, so the default behavior
 * (no row at all) is everything-on — keeping existing notification flows unchanged.
 *
 * quiet_hours_start/end (nullable time) suppress NON-urgent push during that window.
 */
#[ObservedBy([GlobalObserver::class])]
class NotificationPreference extends BaseModel
{
    use CentralConnection;
    use HasFactory;

    /** Supported delivery channels a preference may toggle. */
    public const CHANNELS = ['push', 'email', 'sms', 'whatsapp'];

    protected $table = 'notification_preference';

    protected $fillable = [
        'user_id',
        'channels',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    /**
     * Whether a given channel is enabled for a category. Defaults to enabled when
     * the category or the channel key is absent (everything-on default).
     */
    public function allowsChannel(string $channel, ?string $category = null): bool
    {
        $map = $this->channels ?? [];

        if ($category !== null && array_key_exists($category, $map)) {
            $categoryMap = $map[$category];
            if (is_array($categoryMap) && array_key_exists($channel, $categoryMap)) {
                return (bool) $categoryMap[$channel];
            }
        }

        // Allow a global (category-less) toggle too: channels['_global'][channel].
        if (isset($map['_global']) && is_array($map['_global']) && array_key_exists($channel, $map['_global'])) {
            return (bool) $map['_global'][$channel];
        }

        return true;
    }

    /**
     * Whether the current time falls within the user's quiet-hours window.
     * Supports windows that cross midnight (e.g. 22:00 → 07:00).
     *
     * Quiet hours are wall-clock times in the user's organization (§17.3), so "now" is
     * evaluated in the current tenant's timezone (TenantTime), falling back to the app timezone.
     */
    public function isWithinQuietHours(?string $now = null): bool
    {
        if (! $this->quiet_hours_start || ! $this->quiet_hours_end) {
            return false;
        }

        $current = $now ?? TenantTime::inTenantZone(now())->format('H:i:s');
        $start = $this->normalizeTime($this->quiet_hours_start);
        $end = $this->normalizeTime($this->quiet_hours_end);
        $current = $this->normalizeTime($current);

        if ($start <= $end) {
            return $current >= $start && $current < $end;
        }

        // Window crosses midnight.
        return $current >= $start || $current < $end;
    }

    private function normalizeTime(string $time): string
    {
        // Postgres may return "HH:MM:SS"; keep only the HH:MM:SS comparable form.
        return substr($time, 0, 8);
    }
}
