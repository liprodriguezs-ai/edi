<?php

namespace Modules\Access\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Housing\Models\Unit;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class AccessLog extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'access_log';

    protected $fillable = [
        'visitor_id',
        'unit_id',
        'visitor_name',
        'access_entry_type_id',
        'access_verification_id',
        'authorized_by',
        'entered_at',
        'guard_user_id',
        'id_document_type',
        'id_document_number',
        'plate',
        'visitor_motive_id',
        'denied_at',
        'denied_reason',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'denied_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function access_entry_type(): BelongsTo
    {
        return $this->belongsTo(AccessEntryType::class, 'access_entry_type_id');
    }

    public function access_verification(): BelongsTo
    {
        return $this->belongsTo(AccessVerification::class, 'access_verification_id');
    }

    public function visitor_motive(): BelongsTo
    {
        return $this->belongsTo(VisitorMotive::class, 'visitor_motive_id');
    }

    public function guard_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guard_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    /**
     * Register media collections for access logs.
     * - photo: single visitor photo captured at the gate on manual entries (§14.4.4).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    public function reportColumns(): array
    {
        return [
            'Visitante' => function ($result) {
                return $result->visitor_name ?: $result->visitor?->name;
            },
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Tipo de entrada' => function ($result) {
                return $result->access_entry_type?->name;
            },
            'Verificación' => function ($result) {
                return $result->access_verification?->name;
            },
            'Motivo' => function ($result) {
                return $result->visitor_motive?->name;
            },
            'Autorizó' => function ($result) {
                return $result->authorized_by;
            },
            'Guardia' => function ($result) {
                return $result->guard_user ? trim($result->guard_user->name.' '.$result->guard_user->last_name.' '.($result->guard_user->second_last_name ?? '')) : '';
            },
            'Documento' => function ($result) {
                return trim(($result->id_document_type ?? '').' '.($result->id_document_number ?? ''));
            },
            'Placa' => function ($result) {
                return $result->plate;
            },
            'Entrada' => function ($result) {
                return $result->entered_at ? $result->entered_at->format('d/m/Y H:i') : '';
            },
            'Negado el' => function ($result) {
                return $result->denied_at ? $result->denied_at->format('d/m/Y H:i') : '';
            },
            'Motivo de negación' => function ($result) {
                return $result->denied_reason;
            },
        ];
    }
}
