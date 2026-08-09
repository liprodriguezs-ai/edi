<?php

namespace QCH\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
// use Yajra\Acl\Models\Permission as BasePermission;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $keyType = 'string';

    use HasUuids;

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'name',
        'slug',
        'resource',
        'system',
        'module_id',
    ];
}
