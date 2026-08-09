<?php

namespace Modules\Housing\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Housing\Database\Factories\VehicleFactory;
use Modules\Housing\Observers\VehicleObserver;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([VehicleObserver::class, GlobalObserver::class])]
class Vehicle extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'vehicle';

    protected $fillable = [
        'unit_id',
        'plate',
        'make',
        'model',
        'color',
        'year',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    protected static function newFactory(): VehicleFactory
    {
        return VehicleFactory::new();
    }

    public function reportColumns(): array
    {
        return [
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Placa' => function ($result) {
                return $result->plate;
            },
            'Marca' => function ($result) {
                return $result->make;
            },
            'Modelo' => function ($result) {
                return $result->model;
            },
            'Color' => function ($result) {
                return $result->color;
            },
            'Año' => function ($result) {
                return $result->year;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
