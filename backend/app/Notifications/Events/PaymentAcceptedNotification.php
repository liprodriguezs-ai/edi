<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Notifications\DispatchedNotification;

/**
 * Observability stand-in for the `payment_accepted` business event.
 *
 * Empty on purpose: its only job is to give Nightwatch a per-event class name.
 * Delivery is done by the configured channels, never by Laravel.
 */
final class PaymentAcceptedNotification extends DispatchedNotification {}
