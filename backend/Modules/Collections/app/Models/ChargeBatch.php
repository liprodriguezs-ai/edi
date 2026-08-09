<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class ChargeBatch extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'charge_batch';

    protected $fillable = [
        'charge_policy_id',
        'title',
        'description',
        'year',
        'month',
        'cycle',
        'period_start',
        'target_type',
        'target_selector',
        'issued_at',
        'due_date',
        'charge_batch_status_id',
        'progress_pct',
        'is_initial',
    ];

    protected function casts(): array
    {
        return [
            'target_selector' => 'array',
            'year' => 'integer',
            'month' => 'integer',
            'cycle' => 'integer',
            'period_start' => 'date',
            'progress_pct' => 'integer',
            'issued_at' => 'date',
            'due_date' => 'date',
            'is_initial' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ChargePolicy::class, 'charge_policy_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ChargeBatchStatus::class, 'charge_batch_status_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'charge_batch_id');
    }

    public function reportColumns(): array
    {
        return [
            'Título' => function ($result) {
                return $result->title;
            },
            'Descripción' => function ($result) {
                return $result->description;
            },
            'Año' => function ($result) {
                return $result->year;
            },
            'Mes' => function ($result) {
                return $result->month;
            },
            'Ciclo' => function ($result) {
                return $result->cycle;
            },
            'Política' => function ($result) {
                return $result->policy?->name;
            },
            'F. emisión' => function ($result) {
                return $result->issued_at ? $result->issued_at->format('d/m/Y') : '';
            },
            'F. vencimiento' => function ($result) {
                return $result->due_date ? $result->due_date->format('d/m/Y') : '';
            },
            'Avance (%)' => function ($result) {
                return $result->progress_pct;
            },
            'Estado' => function ($result) {
                return $result->status?->name;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
