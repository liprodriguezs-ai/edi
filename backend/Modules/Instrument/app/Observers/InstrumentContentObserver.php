<?php

namespace Modules\Instrument\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Instrument\Exceptions\ImmutableInstrumentVersionException;
use Modules\Instrument\Models\InstrumentVersion;

/**
 * Guards every versioned instrument content model (design D5 / REQ-INS-002):
 * a create/update/delete/restore on a row whose instrument_version is
 * already published is always rejected. A new edition is a NEW version;
 * there is no valid path to mutate published content -- this observer
 * defends against seeder bugs, tinker, or console mistakes (the read-only
 * API never reaches here at all).
 */
class InstrumentContentObserver
{
    public function saving(Model $model): void
    {
        $this->guard($model);
    }

    public function deleting(Model $model): void
    {
        $this->guard($model);
    }

    public function restoring(Model $model): void
    {
        $this->guard($model);
    }

    private function guard(Model $model): void
    {
        $versionId = $model->instrument_version_id ?? null;

        if ($versionId === null) {
            return;
        }

        $version = InstrumentVersion::query()
            ->whereKey($versionId)
            ->select(['id', 'key', 'published_at'])
            ->first();

        if ($version === null || $version->published_at === null) {
            return;
        }

        throw ImmutableInstrumentVersionException::forContent($model->getTable(), $version->key);
    }
}
