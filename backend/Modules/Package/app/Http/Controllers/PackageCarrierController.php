<?php

namespace Modules\Package\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Package\Models\PackageCarrier;
use QCH\Core\Http\Controllers\Controller;

class PackageCarrierController extends Controller
{
    protected $model = PackageCarrier::class;

    protected $keyForPermission = 'package_carrier';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    /**
     * Prevent FORCE-deleting a carrier that has packages referencing it.
     * Soft-delete is allowed; only the FK-protected physical removal is blocked.
     */
    protected function performForceDestroy(Model $entity): void
    {
        /** @var PackageCarrier $entity */
        if ($entity->packages()->exists()) {
            abort(422, 'No se puede eliminar un transportista vinculado a paquetes existentes');
        }

        parent::performForceDestroy($entity);
    }
}
