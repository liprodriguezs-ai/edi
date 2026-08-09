<?php

namespace Modules\Complaint\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Complaint\Models\ComplaintPriority;
use QCH\Core\Http\Controllers\Controller;

class ComplaintPriorityController extends Controller
{
    protected $model = ComplaintPriority::class;

    protected $keyForPermission = 'complaint_priority';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    /**
     * Prevent FORCE-deleting a priority that has complaints referencing it.
     * Soft-delete is allowed; only the FK-protected physical removal is blocked.
     */
    protected function performForceDestroy(Model $entity): void
    {
        /** @var ComplaintPriority $entity */
        if ($entity->complaints()->exists()) {
            abort(422, 'No se puede eliminar una prioridad vinculada a incidencias existentes');
        }

        parent::performForceDestroy($entity);
    }
}
