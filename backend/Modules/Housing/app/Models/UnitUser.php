<?php

namespace Modules\Housing\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Housing\Database\Factories\UnitUserFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class UnitUser extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'unit_user';

    protected $fillable = [
        'unit_id',
        'user_id',
        'unit_relationship_id',
        'is_billing_owner',
        'is_legal_representative',
        'start_date',
        'end_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_billing_owner' => 'boolean',
            'is_legal_representative' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * The user belongs to the central user table.
     * Bypass UserTenantScope to avoid cross-join issues on a central table.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function unitRelationship(): BelongsTo
    {
        return $this->belongsTo(UnitRelationship::class, 'unit_relationship_id');
    }

    protected static function newFactory(): UnitUserFactory
    {
        return UnitUserFactory::new();
    }

    public function reportColumns(): array
    {
        return [
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Residente' => function ($result) {
                return $result->user ? trim($result->user->name.' '.$result->user->last_name.' '.($result->user->second_last_name ?? '')) : '';
            },
            'Relación' => function ($result) {
                return $result->unitRelationship?->name;
            },
            'Responsable de pago' => function ($result) {
                return $result->is_billing_owner ? 'Sí' : 'No';
            },
            'Representante legal' => function ($result) {
                return $result->is_legal_representative ? 'Sí' : 'No';
            },
            'F. inicio' => function ($result) {
                return $result->start_date ? $result->start_date->format('d/m/Y') : '';
            },
            'F. fin' => function ($result) {
                return $result->end_date ? $result->end_date->format('d/m/Y') : '';
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
