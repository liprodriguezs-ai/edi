<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * AppTimezoneCast — normalizes a Stripe-derived timestamp to the app
 * timezone before it lands in a `timestamp without time zone` column.
 *
 * Carbon 3 changed `Carbon::createFromTimestamp()` to return UTC (Carbon 2
 * returned the app timezone). Cashier persists Stripe's Unix timestamps
 * through that call, so the raw wall-clock value written to the column is
 * skewed by the UTC/app-timezone offset (e.g. +6h for America/Mexico_City)
 * relative to every other datetime column in the schema, which Eloquent
 * writes already in app timezone.
 *
 * Idempotent BY CONSTRUCTION: `setTimezone()` shifts only the DISPLAYED wall
 * clock, never the underlying instant, so re-casting an already-normalized
 * value on a subsequent save is a no-op. No value heuristic is needed to
 * detect "was this already shifted".
 */
class AppTimezoneCast implements CastsAttributes
{
    /**
     * Read the stored wall-clock string as an app-timezone Carbon instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'));
    }

    /**
     * Normalize the incoming value (a UTC Carbon from `createFromTimestamp`,
     * an app-timezone Carbon, or a plain string) to the app-timezone wall
     * clock before it is written to the column.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
