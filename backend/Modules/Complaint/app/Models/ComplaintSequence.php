<?php

namespace Modules\Complaint\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Per-tenant per-year complaint folio counter (D-YYYY-NNNNNN).
 *
 * Rows are read with lockForUpdate() and incremented inside the complaint's
 * register transaction — never read without the lock (that reintroduces the
 * folio race). Mirrors Collections' ReceiptSequence.
 */
#[ObservedBy([GlobalObserver::class])]
class ComplaintSequence extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'complaint_sequence';

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
