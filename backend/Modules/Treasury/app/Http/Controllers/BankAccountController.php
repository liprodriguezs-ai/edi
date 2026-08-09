<?php

namespace Modules\Treasury\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Modules\Treasury\Models\BankAccount;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class BankAccountController extends Controller
{
    protected $model = BankAccount::class;

    protected $keyForPermission = 'bank_account';

    public function includes(): array
    {
        return ['type', 'purpose'];
    }

    public function sortableBy(): array
    {
        return ['name', 'bank', 'currency', 'is_active', 'is_primary'];
    }

    public function searchableBy(): array
    {
        return ['name', 'bank', 'account_number', 'clabe'];
    }

    public function filterableBy(): array
    {
        return ['bank_account_type_id', 'bank_account_purpose_id', 'is_active', 'is_primary', 'currency'];
    }

    /**
     * Override performUpdate so editing initial_balance keeps §9.6 r3 true
     * (saldo = inicial + ingresos − egresos): the delta between the old and new
     * initial balance is applied to current_balance under lockForUpdate.
     * current_balance itself is never writable from the API (prohibited in the
     * request + not mass-assignable on the model).
     *
     * Runs inside Orion's update() transaction (orion.transactions.enabled=true),
     * so the locked read + writes are already atomic and the row lock is held to
     * commit — no extra DB::transaction wrapper needed.
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        $locked = BankAccount::where('id', $entity->getKey())->lockForUpdate()->firstOrFail();

        $oldInitialRaw = (int) $locked->getRawOriginal('initial_balance');

        $locked->fill(Arr::except($attributes, ['current_balance']));

        if (array_key_exists('initial_balance', $attributes)) {
            $deltaRaw = (int) $attributes['initial_balance'] - $oldInitialRaw;

            if ($deltaRaw !== 0) {
                $currentRaw = (int) $locked->getRawOriginal('current_balance');
                $locked->current_balance = $currentRaw + $deltaRaw;
            }
        }

        $locked->save();

        $entity->setRawAttributes($locked->getAttributes());
        $entity->syncOriginal();
    }
}
