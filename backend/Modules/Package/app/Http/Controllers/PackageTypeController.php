<?php

namespace Modules\Package\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Package\Models\PackageType;
use QCH\Core\Http\Controllers\Controller;

class PackageTypeController extends Controller
{
    protected $model = PackageType::class;

    protected $keyForPermission = 'package_type';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    /**
     * Prevent FORCE-deleting a type that has packages referencing it.
     * Soft-delete is allowed; only the FK-protected physical removal is blocked.
     */
    protected function performForceDestroy(Model $entity): void
    {
        /** @var PackageType $entity */
        if ($entity->packages()->exists()) {
            abort(422, 'No se puede eliminar un tipo de paquete vinculado a paquetes existentes');
        }

        parent::performForceDestroy($entity);
    }
}
