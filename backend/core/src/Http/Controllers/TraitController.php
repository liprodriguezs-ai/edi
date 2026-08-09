<?php

namespace QCH\Core\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Request\DelegatingModuleRequest;

trait TraitController
{
    /**
     * Resolve the request class for this controller.
     *
     * When no explicit `protected $request` is declared and Orion's resolver
     * finds no App\Http\Requests\{Model}Request, fall back to
     * DelegatingModuleRequest, which wires the module's sibling
     * {Model}CreateRequest / {Model}UpdateRequest pair into store/update
     * validation instead of Orion's rule-less base Request.
     */
    protected function resolveComponents(): void
    {
        if (! $this->request) {
            $resolved = $this->componentsResolver->resolveRequestClass();

            $this->setRequest($resolved === Request::class ? DelegatingModuleRequest::class : $resolved);
        }

        parent::resolveComponents();
    }

    /**
     * Get the map of resource methods to ability names.
     */
    protected function resourceAbilityMap(): array
    {
        if (! isset($this->keyForPermission)) {
            return [];
        }
        $abilities = [
            'index' => 'read',
            'show' => 'read',
            'create' => 'create',
            'store' => 'create',
            'edit' => 'update',
            'update' => 'update',
            'forceDelete' => 'delete',
            'delete' => 'delete',
            'restore' => 'restore',
        ];

        foreach ($abilities as $ability => $name) {
            $abilities[$ability] = $name.'.'.$this->keyForPermission;
        }

        return $abilities;
    }

    /**
     * The attributes that are used for sorting.
     */
    public function sortableBy(): array
    {
        return ['created_at', 'updated_at'];
    }

    protected function resolveAbility(string $method): string
    {
        return $this->resourceAbilityMap()[$method] ?? $method;
    }

    /**
     * Authorize a given action for the current user.
     *
     * @param  mixed  $ability
     * @param  mixed|array  $arguments
     * @return Response
     *
     * @throws AuthorizationException
     */
    public function authorize($ability, $arguments = [])
    {
        [$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);

        $user = auth()->guard('sanctum')->user();
        if ($user->tokenCan($ability)) {
            return $this->authorized();
        } elseif ($user->tokenCan('*')) {
            return $this->authorized();
        } else {
            throw new AuthorizationException('Unauthorized action.');
        }
    }

    /**
     * Default pagination limit.
     */
    public function limit(): int
    {
        return 15;
    }

    /**
     * Max pagination limit.
     */
    public function maxLimit(): int
    {
        return 100;
    }

    /**
     * Builds Eloquent query for fetching entities in index method.
     */
    public function buildIndexFetchQueryReport(Request $request, array $requestedRelations): Builder
    {
        $filters = collect($request->get('filters', []))
            ->map(function (array $filterDescriptor) use ($request) {
                return $this->beforeFilterApplied($request, $filterDescriptor);
            })->toArray();

        $request->request->add(['filters' => $filters]);

        return $this->buildFetchQuery($request, $requestedRelations);
    }
}
