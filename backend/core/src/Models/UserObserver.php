<?php

namespace QCH\Core\Models;

use Illuminate\Database\Eloquent\Model;

class UserObserver extends GlobalObserver
{
    public function saving(Model $model)
    {
        if (! auth()->check()) {
            return;
        }
        $user = auth()->user();
        if ($user && empty($model?->user_id)) {
            $model->user_id = $user ? $user->id : null;
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
        if ($user && empty($model?->user_id)) {
            $model->user_id = $user ? $user->id : null;
        }

        $model = $this->parseEmpty($model);

        return $model;
    }
}
