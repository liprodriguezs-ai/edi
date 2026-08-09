<?php

namespace Modules\Complaint\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Complaint\Models\ComplaintCategory;
use QCH\Core\Http\Controllers\Controller;

class ComplaintCategoryController extends Controller
{
    protected $model = ComplaintCategory::class;

    protected $keyForPermission = 'complaint_category';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    /**
     * Prevent FORCE-deleting a category that has complaints referencing it.
     * Soft-delete is allowed; only the FK-protected physical removal is blocked.
     */
    protected function performForceDestroy(Model $entity): void
    {
        /** @var ComplaintCategory $entity */
        if ($entity->complaints()->exists()) {
            abort(422, 'No se puede eliminar una categoría vinculada a incidencias existentes');
        }

        parent::performForceDestroy($entity);
    }
}
