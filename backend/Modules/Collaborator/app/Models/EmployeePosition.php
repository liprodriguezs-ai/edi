<?php

namespace Modules\Collaborator\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class EmployeePosition extends TenantCatalogModel
{
    use HasFactory;

    protected $table = 'employee_position';

    /**
     * grants_role: slug del rol Kivo7 que otorga el alta de un empleado con
     * esta posición (security | administrator). Null → operations_staff.
     */
    protected $fillable = [
        'name',
        'order',
        'is_active',
        'grants_role',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
