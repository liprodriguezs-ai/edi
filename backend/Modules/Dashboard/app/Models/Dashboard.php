<?php

namespace Modules\Dashboard\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

// use Modules\Dashboard\Database\Factories\DashboardFactory;
#[ObservedBy([GlobalObserver::class])]
class Dashboard extends BaseModel
{
    use HasFactory;

    protected $table = 'dashboard';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): DashboardFactory
    // {
    //     // return DashboardFactory::new();
    // }
}
