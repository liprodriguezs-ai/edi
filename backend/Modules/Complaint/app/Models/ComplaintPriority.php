<?php

namespace Modules\Complaint\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class ComplaintPriority extends TenantCatalogModel
{
    use HasFactory;

    protected $table = 'complaint_priority';

    protected $fillable = [
        'name',
        'color',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'complaint_priority_id');
    }
}
