<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Database\Factories\VisitFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Visit extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'visit';

    protected $fillable = [
        'child_id',
        'health_center_id',
        'receipt_number',
        'physical_receipt_number',
        'visit_date',
        'practitioner_id',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function health_center(): BelongsTo
    {
        return $this->belongsTo(HealthCenter::class, 'health_center_id');
    }

    /**
     * D6 — the professional identity (license, center) is the clinically
     * meaningful actor, not the raw login user.
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(PractitionerProfile::class, 'practitioner_id');
    }

    protected static function newFactory(): VisitFactory
    {
        return VisitFactory::new();
    }
}
