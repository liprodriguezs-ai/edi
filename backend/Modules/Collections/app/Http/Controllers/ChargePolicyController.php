<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Collections\Http\Requests\ChargePolicyCreateRequest;
use Modules\Collections\Http\Requests\ChargePolicyUpdateRequest;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Services\ChargePolicyService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ChargePolicyController extends Controller
{
    protected $model = ChargePolicy::class;

    protected $keyForPermission = 'charge_policy';

    public function includes(): array
    {
        return ['concept', 'amount_mode', 'periodicity', 'charge_batches', 'surcharge_rules', 'surcharge_rules.surcharge_mode'];
    }

    public function searchableBy(): array
    {
        return ['name', 'concept.name', 'concept.code'];
    }

    public function sortableBy(): array
    {
        return ['name', 'is_active', 'valid_from', 'valid_to'];
    }

    public function filterableBy(): array
    {
        return ['is_active', 'charge_concept_id', 'periodicity_id', 'amount_mode_id'];
    }

    /**
     * Expose `has_billed` (whether the policy owns at least one `charge` row) as a single
     * boolean EXISTS subquery — no N+1, no accessor. The frontend uses it to lock the
     * scope/periodicity fields once the policy has billed.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return parent::buildIndexFetchQuery($request, $requestedRelations)
            ->withExists('charges as has_billed');
    }

    /**
     * Same has_billed exposure for GET /charge_policy/{id}.
     */
    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return parent::buildShowFetchQuery($request, $requestedRelations)
            ->withExists('charges as has_billed');
    }

    /**
     * Same has_billed exposure for POST /charge_policy/search.
     */
    protected function buildSearchFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return parent::buildSearchFetchQuery($request, $requestedRelations)
            ->withExists('charges as has_billed');
    }

    /**
     * Create the policy via the service so a DIRECT policy generates its one-shot
     * batch + charges immediately, inside the same transaction (finding: no orphan policy).
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        app(ChargePolicyCreateRequest::class)->validated();

        $created = app(ChargePolicyService::class)->create($request->validated());

        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * Metadata/config updates: the concept is immutable (request-enforced) and the mode is
     * intrinsic to the periodicity. The optional surcharge_rules array is synced by the
     * service in the same transaction.
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        app(ChargePolicyUpdateRequest::class)->validated();

        /** @var ChargePolicy $entity */
        $updated = app(ChargePolicyService::class)->update($entity, $request->validated());

        $entity->setRawAttributes($updated->getAttributes());
        $entity->syncOriginal();
    }

    /**
     * A policy that already produced LIVE billing cannot be deleted; deactivate it instead.
     * The service owns the rule and cascades the soft-delete to the template/cancelled batches
     * (see ChargePolicyService::delete).
     */
    protected function performDestroy(Model $entity): void
    {
        /** @var ChargePolicy $entity */
        app(ChargePolicyService::class)->delete($entity);
    }
}
