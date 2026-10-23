<?php

namespace Modules\Instrument\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Instrument\Exceptions\ImmutableInstrumentVersionException;
use Modules\Instrument\Models\InstrumentVersion;

/**
 * Guards instrument_version itself (design D5 / REQ-INS-001/002): once a
 * version is published, EVERY field is frozen except is_default (the
 * partial-unique index still enforces at most one default). The publish
 * transition itself (setting published_at for the first time) is allowed --
 * this observer only rejects a change made to a row that was ALREADY
 * published before this write.
 */
class InstrumentVersionObserver
{
    public function saving(InstrumentVersion $version): void
    {
        if (! $version->exists) {
            // Creating a new draft version -- always allowed.
            return;
        }

        $wasPublished = $version->getOriginal('published_at') !== null;

        if (! $wasPublished) {
            // Either still a draft, or this write IS the publish transition.
            return;
        }

        $dirty = array_keys($version->getDirty());
        $forbidden = array_diff($dirty, ['is_default', 'updated_at', 'user_updated_id']);

        if ($forbidden !== []) {
            throw ImmutableInstrumentVersionException::forVersionField(implode(', ', $forbidden), $version->key);
        }
    }

    public function deleting(Model $model): void
    {
        if ($model->published_at !== null) {
            throw ImmutableInstrumentVersionException::forVersionField('deleted_at', $model->key);
        }
    }
}
