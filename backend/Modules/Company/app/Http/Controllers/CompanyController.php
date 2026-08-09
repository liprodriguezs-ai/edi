<?php

namespace Modules\Company\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Company\Http\Requests\CompanyRequest;
use Modules\Company\Models\Company;
use Modules\Company\Services\CompanyService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class CompanyController extends Controller
{
    protected $model = Company::class;

    protected $request = CompanyRequest::class;

    protected $keyForPermission = 'company';

    /**
     * Current tenant's main company ("Mi empresa"). Returns 404 if none.
     */
    public function me(): JsonResponse|JsonResource
    {
        $company = Company::query()->main()->first();

        if (! $company) {
            return response()->json(['message' => 'Main company not found.'], 404);
        }

        return $this->entityResponse($company->load('media'));
    }

    public function filterableBy(): array
    {
        return [
            'name',
        ];
    }

    public function searchableBy(): array
    {
        return [
            'name',
        ];
    }

    /**
     * Delegates create to CompanyService while keeping Orion's store flow
     * (authorization, before/after hooks, refetch, relations, response).
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(CompanyRequest::class)->validated();
        $created = app(CompanyService::class)->create($validated);
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * Delegates update to CompanyService while keeping Orion's update flow
     * (authorization, before/after hooks, refresh, relations, response).
     */
    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(CompanyRequest::class)->validated();
        app(CompanyService::class)->update($entity->getKey(), $validated);
    }
}
