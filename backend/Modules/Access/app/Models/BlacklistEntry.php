<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class BlacklistEntry extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'blacklist_entry';

    protected $fillable = [
        'blacklist_type_id',
        'value',
        'reason',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function blacklist_type(): BelongsTo
    {
        return $this->belongsTo(BlacklistType::class, 'blacklist_type_id');
    }

    public function reportColumns(): array
    {
        return [
            'Tipo' => function ($result) {
                return $result->blacklist_type?->name;
            },
            'Valor' => function ($result) {
                return $result->value;
            },
            'Motivo' => function ($result) {
                return $result->reason;
            },
            'Activa' => function ($result) {
                return $result->is_active ? 'Sí' : 'No';
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
