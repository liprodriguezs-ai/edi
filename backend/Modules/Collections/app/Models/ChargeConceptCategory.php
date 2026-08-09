<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\Concerns\HasCatalogKey;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class ChargeConceptCategory extends TenantCatalogModel
{
    use HasCatalogKey;
    use HasFactory;

    protected $table = 'charge_concept_category';

    /**
     * Key de la fila de sistema que ProjectActivationService usa como
     * categoría default de los conceptos auto-creados; se resuelve por
     * (tenant, key) vía HasCatalogKey, nunca por UUID ni por name.
     */
    public const KEY_PROJECT = 'project';

    /**
     * Key de la fila de sistema que agrupa los conceptos de cobro directo
     * (cargo puntual de mostrador). Protegida por HasCatalogKey: no puede
     * eliminarse ni cambiar su key, así el flujo /charge/direct nunca pierde
     * su categoría de conceptos.
     */
    public const KEY_SERVICES = 'services';

    protected $fillable = [
        'name',
        'order',
        'is_active',
        'key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
