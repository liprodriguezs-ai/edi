<?php

declare(strict_types=1);

namespace Modules\Appointment\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Agenda instants live in `timestamptz` columns and the connection runs in UTC.
 * Eloquent formats a Carbon without its offset, so a "10:00-06:00" value would
 * be stored as 10:00 UTC. This cast normalizes every write to UTC first and
 * always reads back a UTC Carbon (serialized as `...Z`).
 *
 * @implements CastsAttributes<Carbon|null, mixed>
 */
final class UtcInstant implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->utc();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:sP');
    }
}
