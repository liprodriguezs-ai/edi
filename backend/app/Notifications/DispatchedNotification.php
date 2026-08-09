<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Modules\Notification\Jobs\DeliverChannelMessageJob;

/**
 * Observability stand-in for a business notification event.
 *
 * This application does NOT deliver through Laravel's notification pipeline: the
 * engine is the custom NotificationDispatcher + NotificationChannelInterface
 * channels, driven by the per-tenant `notification_setting` configuration. That
 * pipeline never emits `NotificationSending` / `NotificationSent`, which are the
 * ONLY hooks Laravel Nightwatch (and most APM tooling) listens to — so the
 * Notifications panel reads zero even while thousands of messages go out.
 *
 * This class exists purely to give those two events a real notification INSTANCE
 * and, more importantly, a real CLASS NAME: Nightwatch's NotificationSensor
 * persists only `channel`, `class` and `duration`, so the business event key has
 * to be materialised as a class to be visible per event instead of per channel.
 *
 * It is never handed to Laravel for delivery — `via()` returns an empty array, so
 * even an accidental `$notifiable->notify()` would send nothing. The real delivery
 * is done by the channel the DeliverChannelMessageJob resolves.
 *
 * The per-event subclasses live in the `App\Notifications\Events` namespace — one
 * empty class per business event key.
 *
 * @see DeliverChannelMessageJob::handle()
 */
class DispatchedNotification extends Notification
{
    /**
     * Namespace holding the per-event subclasses resolved by {@see self::for()}.
     */
    private const EVENT_NAMESPACE = 'App\\Notifications\\Events\\';

    /**
     * @param  string  $eventKey  business event key (e.g. `payment_accepted`, `amenity_reservation.approved`)
     */
    public function __construct(public readonly string $eventKey) {}

    /**
     * Resolve the per-event notification class for a business event key.
     *
     * `amenity_reservation.approved` → `App\Notifications\Events\AmenityReservationApprovedNotification`.
     * Dotted segments are studly-cased independently because `Str::studly()` only
     * folds `-` and `_`, and would leave the dot in the class name.
     *
     * Falls back to this generic base class when no subclass exists. That fallback
     * is deliberate and MUST stay: a brand-new event key (or a copy variant) has to
     * degrade to a coarser observability label, never to a fatal error that would
     * break the actual delivery.
     */
    public static function for(string $eventKey): self
    {
        $class = self::EVENT_NAMESPACE.collect(explode('.', $eventKey))
            ->map(static fn (string $segment): string => Str::studly($segment))
            ->implode('').'Notification';

        if (class_exists($class)) {
            /** @var self $notification */
            $notification = new $class($eventKey);

            return $notification;
        }

        return new self($eventKey);
    }

    /**
     * Never delivered by Laravel — this instance only names the event for tooling.
     *
     * @return array<int, string>
     */
    public function via(): array
    {
        return [];
    }
}
