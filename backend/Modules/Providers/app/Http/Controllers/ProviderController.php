<?php

namespace Modules\Providers\Http\Controllers;

use App\Exceptions\UnprocessableEntityHttpException;
use Illuminate\Database\Eloquent\Model;
use Modules\Providers\Models\Provider;
use Modules\Treasury\Models\Expense;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class ProviderController extends Controller
{
    protected $model = Provider::class;

    protected $keyForPermission = 'provider';

    /**
     * §10.4.2 (providers-04) — a provider with expense history can only be
     * deactivated, never deleted: the ledger must keep its beneficiary.
     *
     * @throws UnprocessableEntityHttpException
     */
    protected function beforeDestroy(Request $request, Model $entity)
    {
        $hasExpenses = Expense::query()
            ->where('provider_id', $entity->getKey())
            ->exists();

        if ($hasExpenses) {
            throw new UnprocessableEntityHttpException([
                'provider_id' => 'El proveedor tiene egresos asociados; desactívalo en lugar de eliminarlo.',
            ]);
        }

        return null;
    }

    public function includes(): array
    {
        return ['providerType', 'serviceType', 'expenses', 'user'];
    }

    public function filterableBy(): array
    {
        return ['provider_type_id', 'provider_service_type_id', 'is_active', 'is_recurring'];
    }

    public function sortableBy(): array
    {
        return ['name', 'is_active', 'is_recurring', 'frequency'];
    }

    public function searchableBy(): array
    {
        return ['name', 'rfc', 'contact_person', 'phone', 'email'];
    }
}
