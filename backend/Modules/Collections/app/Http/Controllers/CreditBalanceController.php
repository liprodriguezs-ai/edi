<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Modules\Collections\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Collections\Models\CreditBalance;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

/**
 * READ-ONLY resource (routes register index/search/show only): credit_balance is
 * ledger state mutated exclusively by PaymentService — raw Orion writes would let
 * money be fabricated (findings collections-01 / sec-money-01).
 */
class CreditBalanceController extends Controller
{
    use ScopesToResidentUnits;

    protected $model = CreditBalance::class;

    protected $keyForPermission = 'credit_balance';

    public function includes(): array
    {
        return ['unit'];
    }

    public function filterableBy(): array
    {
        return ['unit_id'];
    }

    public function sortableBy(): array
    {
        return ['amount'];
    }

    /**
     * §8.2: residents only read THEIR OWN units' credit (finding collections-02).
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        return $query;
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        return $query;
    }
}
