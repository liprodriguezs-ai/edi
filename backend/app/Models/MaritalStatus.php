<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[ObservedBy([GlobalObserver::class])]
class MaritalStatus extends BaseModel
{
    use CentralConnection;
    use HasFactory;

    protected $table = 'marital_status';

    protected $fillable = [
        'name',
    ];
}
