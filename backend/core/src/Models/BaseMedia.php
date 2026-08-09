<?php

namespace QCH\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Media model: uses UUID as primary key (id). Spatie's HasUuid trait adds
 * a separate uuid column; we disable that so id is the only identifier.
 * We use Laravel's HasUuids so the id is set before save and PathGenerator never gets null.
 */
abstract class BaseMedia extends Media
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public static function bootHasUuid(): void
    {
        // No-op: we use id (UUID) as the only identifier, no separate uuid column.
    }
}
