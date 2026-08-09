<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Per-tenant per-year receipt folio counter (R-YYYY-NNNNNN, spec §8.7.1.3).
 *
 * Rows are read with lockForUpdate() and incremented inside the payment
 * transaction — never read without the lock (that reintroduces the folio race).
 */
#[ObservedBy([GlobalObserver::class])]
class ReceiptSequence extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'receipt_sequence';

    protected $fillable = [
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
