<?php

namespace Modules\Email\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

// use Modules\Email\Database\Factories\EmailFactory;
#[ObservedBy([GlobalObserver::class])]
class Email extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'email';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): EmailFactory
    // {
    //     // return EmailFactory::new();
    // }
}
