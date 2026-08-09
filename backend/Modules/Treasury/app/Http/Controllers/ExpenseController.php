<?php

namespace Modules\Treasury\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Services\ExpenseService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ExpenseController extends Controller
{
    protected $model = Expense::class;

    protected $keyForPermission = 'expense';

    public function includes(): array
    {
        return [
            'category',
            'beneficiaryType',
            'employee',
            'provider',
            'payment_method',
            'bank_account',
            'status',
            'authorizedBy',
            'media',
        ];
    }

    public function filterableBy(): array
    {
        return [
            'expense_category_id',
            'beneficiary_type_id',
            'employee_id',
            'provider_id',
            'bank_account_id',
            'expense_status_id',
            'year',
            'month',
        ];
    }

    public function sortableBy(): array
    {
        return ['paid_at', 'amount', 'concept', 'year', 'month'];
    }

    public function searchableBy(): array
    {
        return ['concept', 'description', 'reference', 'beneficiary_text'];
    }

    /**
     * Attributes accepted on store — the ExpenseCreateRequest surface. Orion fills
     * from $request->all() (orion.use_validated=false), so the raw attributes are
     * re-filtered here: status/void fields can never be injected on creation.
     *
     * @var array<int, string>
     */
    private const CREATE_ATTRIBUTES = [
        'concept', 'description', 'paid_at', 'amount', 'expense_category_id',
        'beneficiary_type_id', 'employee_id', 'provider_id', 'beneficiary_text',
        'year', 'month', 'fortnight', 'payment_method_id', 'bank_account_id',
        'reference', 'community_project_id', 'authorized_by_user_id', 'notes',
    ];

    /**
     * Override performStore to use ExpenseService (duplicate detection + balance update).
     * When a duplicate is detected and force=false, a 409 is returned immediately.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $force = (bool) $request->input('force', false);

        $service = app(ExpenseService::class);
        $result = $service->register(Arr::only($attributes, self::CREATE_ATTRIBUTES), $force);

        if ($result['duplicate']) {
            // Abort with 409 so Orion doesn't try to save the model.
            abort(409, 'Posible egreso duplicado detectado. Envíe force=true para confirmar.');
        }

        $saved = $result['expense'];
        $entity->setRawAttributes($saved->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * Override performUpdate: only cosmetic fields are editable (§9.6 r1, enforced
     * by ExpenseUpdateRequest + the service allowlist) and the treasurer's edit
     * window is same-day only (§9.2 ⚠️).
     *
     * The window discriminator is the void.expense ability: per the §9.2 matrix the
     * roles allowed to void (president/administrator/account owner) are exactly the
     * roles with an unrestricted edit window; update.expense holders without it
     * (treasurer) may only edit expenses captured TODAY in the tenant's timezone.
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        /** @var Expense $expense */
        $expense = $entity;

        $user = $request->user();

        if (! $user->tokenCan('void.expense') && ! $user->tokenCan('*')) {
            $timezone = tenant('timezone') ?: config('app.timezone');

            if (! $expense->created_at->copy()->setTimezone($timezone)->isSameDay(now($timezone))) {
                abort(422, 'Solo puedes editar egresos capturados hoy. Para corregir un egreso anterior, anúlalo y regístralo de nuevo.');
            }
        }

        $updated = app(ExpenseService::class)->update($expense, $attributes);

        $entity->setRawAttributes($updated->getAttributes());
        $entity->syncOriginal();
    }

    /**
     * Expenses are NEVER deleted — only voided with a reason (§9.6 r1). Blocking
     * performDestroy covers destroy AND batchDestroy (beforeDestroy does not).
     */
    protected function performDestroy(Model $entity): void
    {
        abort(422, 'Los egresos no se eliminan; anúlalos con motivo vía POST /expense/{id}/void.');
    }

    /**
     * Force delete is equally forbidden (§9.6 r1).
     */
    protected function performForceDestroy(Model $entity): void
    {
        abort(422, 'Los egresos no se eliminan; anúlalos con motivo vía POST /expense/{id}/void.');
    }

    /**
     * Custom action: POST /expense/{id}/void
     *
     * Gated by the dedicated void.expense ability (§9.2: Anular egreso —
     * Tes ❌, Pres ✅, Adm ✅), NOT by the broad update.expense the treasurer holds.
     */
    public function void(Request $request, string $key): JsonResponse
    {
        $this->authorize('void.expense');

        $expense = Expense::findOrFail($key);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        $voided = app(ExpenseService::class)->void($expense, $reason);

        return response()->json(['data' => $voided]);
    }

    /**
     * POST /expense/{id}/media — attach a comprobante (receipt) or a CFDI file
     * (spec §9.4.2, §9.4.3). `collection` selects the target: receipt (single) or
     * cfdi (XML + PDF, up to 2). Authorized via update.expense.
     */
    public function uploadMedia(Request $request, string $key): JsonResponse
    {
        $this->authorize('update.expense');

        $validated = $request->validate([
            'collection' => ['required', 'string', 'in:receipt,cfdi'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $expense = Expense::findOrFail($key);

        $media = $expense->addMedia($request->file('file'))
            ->toMediaCollection($validated['collection']);

        return response()->json(['data' => $media], 201);
    }
}
