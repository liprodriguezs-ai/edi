<?php

declare(strict_types=1);

namespace QCH\Core\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stancl\Tenancy\Tenancy;

/**
 * The ONE place that turns instants into tenant civil time and back.
 *
 * Storage convention: every `timestamp without time zone` column holds UTC
 * (app.timezone = UTC, pgsql connection timezone = UTC). Civil labels ("today",
 * "this month", a wall-clock time typed by a user) belong to the tenant's IANA
 * zone, resolved on EVERY call from `tenant()->timezone`. This class never
 * changes PHP's default timezone or the application config, and never caches
 * the zone statically (Octane reuses the process across tenants).
 */
final class TenantTime
{
    /** The current tenant's zone; falls back to the application zone, then UTC. */
    public static function zone(): string
    {
        if (app()->bound(Tenancy::class) && tenancy()->initialized) {
            $zone = tenant()?->timezone;
            if (is_string($zone) && self::isValidZone($zone)) {
                return $zone;
            }
        }

        return self::fallbackZone();
    }

    public static function isValidZone(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /** Today's civil date (Y-m-d) in the tenant's calendar, never the process calendar. */
    public static function today(?string $zone = null): string
    {
        return CarbonImmutable::now(self::resolveZone($zone))->format('Y-m-d');
    }

    /** The current civil month (Y-m) in the tenant's calendar. */
    public static function currentMonth(?string $zone = null): string
    {
        return CarbonImmutable::now(self::resolveZone($zone))->format('Y-m');
    }

    /** Converts an instant to the tenant zone. Offset-less strings are read as UTC (storage). */
    public static function inTenantZone(DateTimeInterface|string $value, ?string $zone = null): CarbonImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, 'UTC');

        return $instant->setTimezone(self::resolveZone($zone));
    }

    /** Strict wall-clock input: no offset, no rollover, no DST gap, no ambiguous fold. Returns UTC. */
    public static function wallTime(string $value, ?string $zone = null): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/D', $value)) {
            throw new InvalidArgumentException('La fecha y hora local no es válida.');
        }

        $normalized = str_replace('T', ' ', $value);
        if (strlen($normalized) === 16) {
            $normalized .= ':00';
        }
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, 'UTC');
        if ($wall === false || $wall->format('Y-m-d H:i:s') !== $normalized) {
            throw new InvalidArgumentException('La fecha y hora local no es válida.');
        }

        $timezone = new DateTimeZone(self::resolveZone($zone));
        $timestamp = $wall->getTimestamp();
        $transitions = $timezone->getTransitions($timestamp - 172800, $timestamp + 172800);
        $offsets = [$timezone->getOffset($wall)];
        foreach ($transitions ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }

        // Enumerate every candidate offset instead of accepting PHP's silent DST normalization.
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($timestamp - $offset);
            if ($candidate->setTimezone($timezone)->format('Y-m-d H:i:s') === $normalized) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('La hora local no existe o es ambigua en esta zona horaria.');
        }

        return $matches[0];
    }

    /** A civil date stays a date; it is never shifted by any zone. */
    public static function civilDate(string $value): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('La fecha no es válida.');
        }

        return $value;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} UTC half-open [start of day, start of next day). */
    public static function dayBounds(string $date, ?string $zone = null): array
    {
        $date = self::civilDate($date);
        $zone = self::resolveZone($zone);
        $next = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->addDay()->format('Y-m-d');

        return [self::wallTime($date.' 00:00:00', $zone), self::wallTime($next.' 00:00:00', $zone)];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} UTC half-open [first day 00:00, next month 00:00). */
    public static function monthBounds(string $month, ?string $zone = null): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/D', $month, $parts) || (int) $parts[2] < 1 || (int) $parts[2] > 12) {
            throw new InvalidArgumentException('El periodo no es válido.');
        }
        $zone = self::resolveZone($zone);
        $first = $month.'-01';
        $next = CarbonImmutable::createFromFormat('!Y-m-d', $first, 'UTC')->addMonthNoOverflow()->format('Y-m-d');

        return [self::wallTime($first.' 00:00:00', $zone), self::wallTime($next.' 00:00:00', $zone)];
    }

    private static function fallbackZone(): string
    {
        $zone = config('app.timezone', 'UTC');

        return is_string($zone) && self::isValidZone($zone) ? $zone : 'UTC';
    }

    private static function resolveZone(?string $zone): string
    {
        if ($zone === null) {
            return self::zone();
        }
        if (! self::isValidZone($zone)) {
            throw new InvalidArgumentException('La zona horaria no es válida.');
        }

        return $zone;
    }
}
