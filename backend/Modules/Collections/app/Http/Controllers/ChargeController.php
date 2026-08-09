<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Modules\Collections\Http\Controllers\Concerns\ResolvesPaymentMethodRules;
use Modules\Collections\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Collections\Models\Charge;
use Modules\Collections\Services\ChargeService;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\DirectChargeService;
use Modules\Collections\Services\PaymentService;
use Modules\Collections\Support\MoneyLimit;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ChargeController extends Controller
{
    use ResolvesPaymentMethodRules;
    use ScopesToResidentUnits;

    protected $model = Charge::class;

    protected $keyForPermission = 'charge';

    public function includes(): array
    {
        return ['concept', 'charge_status', 'batch', 'unit', 'payment_applications'];
    }

    public function searchableBy(): array
    {
        // Solo campos de texto: Orion envuelve cada columna en lower(), y unit_id es
        // UUID → Postgres "lower(uuid) does not exist" (500). El filtrado por unidad va
        // por el filtro unit_id (filterableBy), no por búsqueda de texto. concept.name /
        // concept.code permiten buscar por el nombre/clave del concepto desde /charge.
        return ['concept.name', 'concept.code'];
    }

    public function sortableBy(): array
    {
        return ['due_date', 'issued_at', 'expected_amount', 'paid_amount'];
    }

    public function filterableBy(): array
    {
        return [
            'unit_id',
            'charge_batch_id',
            'charge_concept_id',
            'charge_status_id',
            'is_cancelled',
            'expected_amount',
            'paid_amount',
            'due_date',
            'issued_at',
        ];
    }

    /**
     * §8.2: residents only read THEIR OWN units' charges (finding collections-02).
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        $this->applyOwnerFilter($query, $request);

        return $query;
    }

    /**
     * A foreign unit's charge resolves to 404 for residents (no information leak).
     */
    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        return $query;
    }

    /**
     * POST /charge/search: apply resident scoping + owner filter on the search path.
     */
    protected function buildSearchFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildSearchFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        $this->applyOwnerFilter($query, $request);

        return $query;
    }

    /**
     * Filter charges by billing owner user ids (plan §8.2).
     *
     * The billing owner is stored in unit_user.is_billing_owner. Charges have no
     * direct FK to a user, so the relationship is: charge → unit → unit_user (where
     * is_billing_owner = true). The frontend sends a custom field "owner_ids" as an
     * Orion scope to avoid collision with filterableBy fields.
     *
     * Each element is validated as a UUID string before use; malformed values are
     * silently filtered out to prevent a PostgreSQL type error (500).
     */
    private function applyOwnerFilter(Builder $query, OrionRequest $request): void
    {
        $rawIds = $request->input('owner_ids');

        if (! is_array($rawIds) || count($rawIds) === 0) {
            return;
        }

        $ownerIds = array_values(array_filter(
            $rawIds,
            static fn (mixed $id): bool => is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1
        ));

        if (count($ownerIds) === 0) {
            return;
        }

        $query->whereHas('unit.unitUsers', function (Builder $q) use ($ownerIds): void {
            $q->where('is_billing_owner', true)
                ->whereIn('user_id', $ownerIds);
        });
    }

    /**
     * Ad-hoc charge creation (spec §8.7.2.1 "manualmente"). Only validated domain
     * fields are filled — ledger/FSM columns (paid_amount, charge_status_id, …) are
     * service-owned and never client-writable (findings collections-01 / sec-input-01).
     * Runs inside Orion's store transaction.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $validated = $request->validated();

        $entity->fill(Arr::only($validated, [
            'charge_concept_id', 'unit_id', 'expected_amount', 'issued_at', 'due_date', 'grace_days',
        ]));
        $entity->paid_amount = 0;
        $entity->is_cancelled = false;

        $statusService = app(ChargeStatusService::class);
        $entity->charge_status_id = $statusService->resolveStatusKey($entity);
        $entity->save();

        // Flujo C.5 (§8.7.3.4): a new charge consumes any available unit credit.
        app(PaymentService::class)->applyUnitCreditFifo($entity->unit_id);

        $entity->refresh();
        $statusService->recomputeUnitAggregate($entity->unit_id);
    }

    /**
     * §8.7.2.2-3: a charge with payments applied is immutable; without payments only
     * schedule fields may change. Amount/status corrections go through cancel+recreate.
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        /** @var Charge $entity */
        if ($entity->payment_applications()->exists()) {
            abort(422, 'No se puede modificar un cargo con pagos aplicados. Cancélalo y crea uno nuevo.');
        }

        if ($entity->is_cancelled) {
            abort(422, 'No se puede modificar un cargo cancelado.');
        }

        $allowed = Arr::only($request->validated(), ['issued_at', 'due_date', 'grace_days']);

        $entity->fill($allowed);
        $entity->save();

        // due_date/grace changes can move the status (pending ↔ due_soon ↔ overdue).
        $statusService = app(ChargeStatusService::class);
        $statusService->recomputeCharge($entity);
        $statusService->recomputeUnitAggregate($entity->unit_id);
    }

    /**
     * §8.7.2: charges are never deleted — they are cancelled with a reason.
     */
    protected function performDestroy(Model $entity): void
    {
        abort(422, 'Los cargos no se eliminan; cancélalos con motivo (POST charge/{id}/cancel).');
    }

    /**
     * POST charge/{id}/cancel — cancel a charge with reason (spec §8.7.2.4).
     *
     * Authorized via the dedicated `cancel.charge` ability (President/Admin only —
     * finding collections-03): the Tesorero holds the broad `update.charge` but must
     * NOT be able to cancel. Same split as discount.charge below.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        if (! $user || ! ($user->tokenCan('cancel.charge') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $request->validate(['reason' => ['required', 'string', 'min:10']]);

        $charge = Charge::findOrFail($id);

        try {
            $cancelled = app(ChargeService::class)->cancel($charge, $request->input('reason'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $cancelled]);
    }

    /**
     * POST charge/{id}/discount — condone part or all of a charge (spec §8.2).
     *
     * Authorized for President/Admin ONLY via the dedicated `discount.charge` ability
     * — the Tesorero (who holds the broad `update.charge`) must NOT be able to condone.
     */
    public function discount(Request $request, string $id): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        if (! $user || ! ($user->tokenCan('discount.charge') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'reason' => ['required', 'string', 'min:5'],
        ]);

        $charge = Charge::findOrFail($id);

        try {
            $discounted = app(ChargeService::class)->applyDiscount(
                $charge,
                (int) $validated['amount'],
                $validated['reason'],
                $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $discounted]);
    }

    /**
     * POST charge/direct — create an ad-hoc charge and cobra it in the SAME act (cobro
     * directo de mostrador). Delegates to DirectChargeService, which registers a payment
     * PINNED to the new charge (manual application) so FIFO can never divert the money to
     * old debt — a direct charge is always born paid and pinned, or it is not born.
     *
     * Requires BOTH create.charge AND create.payment (Admin/President/Tesorero hold both).
     * No new Phase-1 permission. The comprobante is required for non-cash methods (same rule
     * as payment/register); cash stays optional.
     */
    public function direct(Request $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $canCharge = $user && ($user->tokenCan('create.charge') || $user->tokenCan('*'));
        $canPay = $user && ($user->tokenCan('create.payment') || $user->tokenCan('*'));

        if (! $canCharge || ! $canPay) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $validated = $request->validate([
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            // Either a catalog concept OR a free-text motive ("Otro") must be provided.
            'charge_concept_id' => ['nullable', 'required_without:concept_free_text', 'uuid', Rule::exists('charge_concept', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'concept_free_text' => ['nullable', 'required_without:charge_concept_id', 'prohibits:charge_concept_id', 'string', 'max:255'],
            // §18.7.4: amounts are positive and < $10,000,000 MXN (minor units cap).
            'expected_amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'payment_method_id' => ['required', 'uuid', Rule::exists('payment_method', 'id')],
            'bank_account_id' => ['required', 'uuid', Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'paid_at' => ['nullable', 'date'],
            'issued_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'physical_receipt_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'concept_free_text.prohibits' => 'Elige un concepto del catálogo o escribe un motivo libre, pero no ambos.',
        ]);

        // §16.3.4: a payment method disabled in cluster configuration cannot be used.
        if (! $this->paymentMethodIsEnabled($validated['payment_method_id'])) {
            return response()->json([
                'message' => 'El método de pago seleccionado no está habilitado en la configuración del condominio.',
            ], 422);
        }

        // §8.7.1 r5: comprobante is REQUIRED for non-cash methods (cash stays optional).
        if ($this->requiresReceipt($validated['payment_method_id']) && ! $request->hasFile('receipt')) {
            return response()->json([
                'message' => 'El comprobante es obligatorio para pagos que no son en efectivo.',
            ], 422);
        }

        try {
            $result = app(DirectChargeService::class)->create($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->hasFile('receipt')) {
            $result['payment']->addMedia($request->file('receipt'))->toMediaCollection('receipt');
        }

        // 201 body returns the models AS-IS (no ->load): the FE only reads
        // payment.receipt_number (a plain column, already present). register() already
        // eager-loaded applications/payment_status/payment_method inside its own
        // transaction, so no extra relation queries are issued here.
        return response()->json([
            'data' => [
                'charge' => $result['charge'],
                'payment' => $result['payment'],
            ],
        ], 201);
    }
}
