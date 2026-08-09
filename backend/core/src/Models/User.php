<?php

namespace QCH\Core\Models;

trait User
{
    /**
     * Boot the soft deleting trait for a model.
     *
     * @return void
     */
    public static function bootUser()
    {
        static::addGlobalScope(new UserScope);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
