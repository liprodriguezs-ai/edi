<?php

namespace QCH\Core\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalObserver
{
    public function saving(Model $model)
    {
        if (! auth()->check()) {
            return;
        }
        $user = auth()->user();

        // tenant_id is auto-stamped by Stancl's BelongsToTenant trait (single-DB tenancy),
        // not here. This observer only owns the audit columns.
        if ($model->getTable() !== 'audits') {
            $model->user_created_id = $user->id ?? null;
            $model->user_updated_id = $user->id ?? null;
        }
        $model = $this->parseEmpty($model);

        return $model;
    }

    public function creating(Model $model)
    {
        if (! auth()->check()) {
            return;
        }
        $user = auth()->user();
        if ($model->getTable() !== 'audits') {
            $model->user_created_id = $user->id ?? null;
        }
        $model = $this->parseEmpty($model);

        return $model;
    }

    public function updating(Model $model)
    {
        if (! auth()->check()) {
            return;
        }

        if ($model->getTable() !== 'audits') {
            $model->user_updated_id = auth()->user()->id ?? null;
        }
        $model = $this->parseEmpty($model);

        return $model;
    }

    protected function parseEmpty(Model $model)
    {

        foreach ($model->getAttributes() as $name => $value) {
            if ($model?->translatable && in_array($name, $model->translatable)) {
                continue;
            }

            // Skip cast attributes (array/json/object/collection/date/etc.). For a
            // JSON-cast column the raw attribute is ALREADY a serialized string at
            // this point; re-assigning it via the mutator would run the cast a
            // second time and double-encode the value. The trim/empty normalization
            // below is only meaningful for plain string columns.
            if ($model->hasCast($name)) {
                continue;
            }

            if (is_string($value)) {
                $model->{$name} = trim($value ?? '');
            }

            if ($value === '' || $value === 'null') {
                $model->{$name} = null;
            }
        }

        return $model;
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(Model $model)
    {
        if (! auth()->check()) {
            return;
        }

        if ($model->getTable() !== 'audits') {
            $model->user_deleted_id = auth()->user()->id ?? null;
            $model->saveQuietly();
        }

        return $model;
    }

    public function restored(Model $model)
    {
        if (! auth()->check()) {
            return;
        }

        if ($model->getTable() !== 'audits') {
            $model->user_restored_id = auth()->user()->id ?? null;
            $model->saveQuietly();
        }
        $model = $this->parseEmpty($model);

        return $model;
    }
}
