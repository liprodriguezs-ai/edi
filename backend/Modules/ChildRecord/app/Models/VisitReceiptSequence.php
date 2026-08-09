<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Consumed only by VisitService under lockForUpdate() — never exposed via an
 * Orion resource.
 */
#[ObservedBy([GlobalObserver::class])]
class VisitReceiptSequence extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'visit_receipt_sequence';

    protected $fillable = [
        'year',
        'last_number',
    ];
}
