<?php

namespace Modules\Access\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Housing\Models\Unit;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Visitor extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'visitor';

    protected $fillable = [
        'unit_id',
        'created_by_user_id',
        'name',
        'visitor_motive_id',
        'arrives_by_vehicle',
        'plate',
        'vehicle_model',
        'id_document_type',
        'id_document_number',
        'qr_token',
        'qr_type_id',
        'qr_expires_at',
        'visitor_status_id',
        'notes',
    ];

    /**
     * §14.2 security: qr_token is the entry credential. It is NEVER serialized by
     * default so staff with read.access_log cannot read another resident's token
     * via index/show/search or via AccessLog ?include=visitor. Only the OWNER
     * gets it back, via ->makeVisible('qr_token') on the self-scoped paths.
     *
     * @var list<string>
     */
    protected $hidden = [
        'qr_token',
    ];

    protected function casts(): array
    {
        return [
            'arrives_by_vehicle' => 'boolean',
            'qr_expires_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function created_by_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function visitor_motive(): BelongsTo
    {
        return $this->belongsTo(VisitorMotive::class, 'visitor_motive_id');
    }

    public function qr_type(): BelongsTo
    {
        return $this->belongsTo(QrType::class, 'qr_type_id');
    }

    public function visitor_status(): BelongsTo
    {
        return $this->belongsTo(VisitorStatus::class, 'visitor_status_id');
    }

    public function access_logs(): HasMany
    {
        return $this->hasMany(AccessLog::class, 'visitor_id');
    }

    /**
     * Register media collections for visitors.
     * - photo: single visitor photo (for identification purposes).
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
            'Nombre' => function ($result) {
                return $result->name;
            },
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Motivo' => function ($result) {
                return $result->visitor_motive?->name;
            },
            'Llega en vehículo' => function ($result) {
                return $result->arrives_by_vehicle ? 'Sí' : 'No';
            },
            'Placa' => function ($result) {
                return $result->plate;
            },
            'Documento' => function ($result) {
                return trim(($result->id_document_type ?? '').' '.($result->id_document_number ?? ''));
            },
            'Tipo de QR' => function ($result) {
                return $result->qr_type?->name;
            },
            'QR expira' => function ($result) {
                return $result->qr_expires_at ? $result->qr_expires_at->format('d/m/Y H:i') : '';
            },
            'Estado' => function ($result) {
                return $result->visitor_status?->name;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
