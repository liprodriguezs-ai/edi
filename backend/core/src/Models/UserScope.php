<?php

namespace QCH\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class UserScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {

        $table = $model->getTable();
        $user = auth()->user();

        if ($table !== 'user') {
            $builder->where($table.'.user_id', $user ? $user->id : null);
        }
    }
}
