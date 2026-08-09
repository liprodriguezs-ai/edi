<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Modules\Collections\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Collections\Models\PaymentApplication;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

/**
 * READ-ONLY resource (routes register index/search/show only): payment_application
 * is the FIFO ledger written exclusively by PaymentService — raw Orion writes would
 * corrupt the money trail (findings collections-01 / sec-money-01).
 */
class PaymentApplicationController extends Controller
{
    use ScopesToResidentUnits;

    protected $model = PaymentApplication::class;

    protected $keyForPermission = 'payment_application';

    public function includes(): array
    {
        return ['payment', 'charge'];
    }

    public function filterableBy(): array
    {
        return ['payment_id', 'charge_id'];
    }

    /**
     * §8.2: residents only read applications on THEIR OWN units' charges
     * (finding collections-02).
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereHas('charge', fn (Builder $q) => $q->whereIn('unit_id', $this->ownUnitIds()));
        }

        return $query;
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereHas('charge', fn (Builder $q) => $q->whereIn('unit_id', $this->ownUnitIds()));
        }

        return $query;
    }
}
