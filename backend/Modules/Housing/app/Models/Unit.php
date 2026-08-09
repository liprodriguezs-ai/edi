<?php

namespace Modules\Housing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Housing\Database\Factories\UnitFactory;
use Modules\Housing\Observers\UnitObserver;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([UnitObserver::class, GlobalObserver::class])]
class Unit extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'unit';

    protected $fillable = [
        'unit_type_id',
        'number',
        'street',
        'interior_number',
        'tower_id',
        'floor',
        'section_id',
        'built_m2',
        'land_m2',
        'ownership_share',
        'unit_status_id',
        'notes',
        'characteristics',
    ];

    /** @var list<string> */
    protected $appends = [
        'full_identifier',
    ];

    protected function casts(): array
    {
        return [
            'built_m2' => 'decimal:2',
            'land_m2' => 'decimal:2',
            'ownership_share' => 'decimal:4',
            'characteristics' => 'array',
        ];
    }

    /**
     * Human-readable unit identifier (§6.5 r6).
     *
     * Houses → "Casa 23". Apartments → "Torre Norte · Piso 5 · Depto 502", composed
     * from the tower name, floor and number. Falls back gracefully when parts are
     * missing so the accessor never throws.
     */
    protected function fullIdentifier(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->buildFullIdentifier(),
        );
    }

    private function buildFullIdentifier(): string
    {
        $typeLabel = $this->unitType?->name ?? 'Unidad';

        if ($this->unitType?->requires_floor) {
            $parts = [];
            if ($this->tower?->name) {
                $parts[] = 'Torre '.$this->tower->name;
            }
            if ($this->floor !== null && $this->floor !== '') {
                $parts[] = 'Piso '.$this->floor;
            }
            $parts[] = 'Depto '.($this->number ?? 's/n');

            return implode(' · ', $parts);
        }

        return trim($typeLabel.' '.($this->number ?? 's/n'));
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_type_id');
    }

    public function unitStatus(): BelongsTo
    {
        return $this->belongsTo(UnitStatus::class, 'unit_status_id');
    }

    public function tower(): BelongsTo
    {
        return $this->belongsTo(Tower::class, 'tower_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function unitUsers(): HasMany
    {
        return $this->hasMany(UnitUser::class, 'unit_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'unit_user', 'unit_id', 'user_id')
            ->using(UnitUser::class)
            ->withPivot(['unit_relationship_id', 'is_billing_owner', 'is_legal_representative', 'start_date', 'end_date', 'notes']);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'unit_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(UnitFeature::class, 'unit_unit_feature', 'unit_id', 'unit_feature_id');
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }

    public function reportColumns(): array
    {
        return [
            'Identificador' => function ($result) {
                return $result->full_identifier;
            },
            'Número' => function ($result) {
                return $result->number;
            },
            'Número interior' => function ($result) {
                return $result->interior_number;
            },
            'Calle' => function ($result) {
                return $result->street;
            },
            'Tipo' => function ($result) {
                return $result->unitType?->name;
            },
            'Torre' => function ($result) {
                return $result->tower?->name;
            },
            'Piso' => function ($result) {
                return $result->floor;
            },
            'Sección' => function ($result) {
                return $result->section?->name;
            },
            'm² construidos' => function ($result) {
                return $result->built_m2 !== null ? (float) $result->built_m2 : 0;
            },
            'm² terreno' => function ($result) {
                return $result->land_m2 !== null ? (float) $result->land_m2 : 0;
            },
            'Estado' => function ($result) {
                return $result->unitStatus?->name;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
