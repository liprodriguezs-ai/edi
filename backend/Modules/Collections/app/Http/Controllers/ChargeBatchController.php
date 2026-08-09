<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Modules\Collections\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\TargetResolver;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Support\TenantMoney;

class ChargeBatchController extends Controller
{
    use ScopesToResidentUnits;

    protected $model = ChargeBatch::class;

    protected $keyForPermission = 'charge_batch';

    public function includes(): array
    {
        return ['policy', 'policy.concept', 'status', 'charges'];
    }

    public function searchableBy(): array
    {
        return ['title', 'description'];
    }

    public function sortableBy(): array
    {
        return ['title', 'year', 'month', 'period_start', 'issued_at', 'due_date', 'progress_pct'];
    }

    public function filterableBy(): array
    {
        return ['charge_batch_status_id', 'charge_policy_id', 'year', 'month', 'period_start', 'is_initial'];
    }

    /**
     * Hide template batches (is_initial=true) from the Orion index and search results by
     * default. Templates are pure configuration placeholders cloned by the cron — they
     * never carry charges and must not appear in COBROS GENERADOS. The frontend still sends
     * the explicit filter `is_initial=false`, which is redundant with this scope but harmless
     * (is_initial remains in filterableBy() so the filter is accepted without a 422).
     *
     * NOTE: in this Orion version POST /search delegates to index(), which calls
     * buildIndexFetchQuery() — a single override covers both endpoints.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return parent::buildIndexFetchQuery($request, $requestedRelations)->where('is_initial', false);
    }

    /**
     * Batches are ALWAYS born DRAFT with 0 progress; lifecycle columns are
     * service-owned (finding collections-13). Only validated fields are filled.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $entity->fill(Arr::only($request->validated(), [
            'charge_policy_id', 'title', 'description', 'year', 'month',
            'target_type', 'target_selector', 'issued_at', 'due_date',
        ]));
        $entity->charge_batch_status_id = ChargeBatchStatus::DRAFT;
        $entity->progress_pct = 0;
        $entity->save();

        // If the user requests immediate activation.
        if ($request->boolean('activate')) {
            app(ChargeGenerationService::class)->activateBatch($entity);
            $entity->refresh();
        }
    }

    /**
     * Metadata-only updates: the lifecycle (charge_batch_status_id, progress_pct)
     * never moves via raw PATCH — CANCELLED is only reachable through the cancel
     * endpoint, which also cancels the generated charges (finding collections-13).
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        $entity->fill(Arr::only($request->validated(), [
            'title', 'description', 'year', 'month',
            'target_type', 'target_selector', 'issued_at', 'due_date',
        ]));
        $entity->save();
    }

    /**
     * POST charge-batch/{id}/activate — activate a draft batch.
     */
    public function activate(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.charge_batch', ChargeBatch::class);

        $batch = ChargeBatch::findOrFail($id);

        try {
            $batch = app(ChargeGenerationService::class)->activateBatch($batch);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $batch->load('charges', 'status', 'policy', 'policy.concept')]);
    }

    /**
     * POST charge-batch/{id}/cancel — cancel a batch AND its unpaid charges in one
     * transaction (spec §8.4 / §8.7.2.5 — finding collections-13). 422 when any
     * charge already has payments applied.
     */
    public function cancelBatch(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.charge_batch', ChargeBatch::class);

        $request->validate(['reason' => ['required', 'string', 'min:5']]);

        $batch = ChargeBatch::findOrFail($id);

        try {
            $cancelled = app(ChargeGenerationService::class)->cancelBatch($batch, $request->input('reason'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $cancelled]);
    }

    /**
     * POST charge-batch/preview — return target unit count for a given selector.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('read.charge_batch', ChargeBatch::class);

        $validated = $request->validate([
            'target_type' => ['nullable', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => ['nullable', 'array'],
        ]);

        $count = app(TargetResolver::class)->previewCount(
            $validated['target_type'] ?? 'all',
            $validated['target_selector'] ?? null,
        );

        return response()->json(['count' => $count]);
    }

    /**
     * GET charge-batch/{id}/summary — server-side KPI aggregation over ALL non-cancelled
     * charges in the batch. Replaces the previous client reduce over a 100-row sample,
     * which truncated (and silently corrupted) the KPIs for batches with > 100 charges.
     *
     * Every value is a raw integer (minor units / count) — no money formatting. Per-row
     * outstanding is CLAMPED to 0 with CASE WHEN (not GREATEST, so SQLite agrees): an
     * overpaid charge contributes 0 to outstanding, never a negative that would offset
     * other rows. Residents are restricted to their own units — the SAME scope
     * ChargeController applies — so the headline KPIs match the table they can see.
     */
    public function summary(Request $request, string $id): JsonResponse
    {
        $this->authorize('read.charge_batch', ChargeBatch::class);

        // 404 unknown ids; TenantScope makes a foreign-tenant batch 404 automatically.
        ChargeBatch::findOrFail($id);

        // Charge::query() applies TenantScope + soft-deletes automatically.
        $query = Charge::query()
            ->where('charge_batch_id', $id)
            ->where('is_cancelled', false);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        $row = $query->selectRaw(<<<'SQL'
            COALESCE(SUM(expected_amount), 0) AS expected,
            COALESCE(SUM(paid_amount), 0) AS paid,
            COALESCE(SUM(CASE WHEN (expected_amount - discount_amount + surcharge_amount - paid_amount) > 0 THEN (expected_amount - discount_amount + surcharge_amount - paid_amount) ELSE 0 END), 0) AS outstanding,
            COUNT(*) AS total,
            COUNT(CASE WHEN (expected_amount - discount_amount + surcharge_amount - paid_amount) <= 0 THEN 1 END) AS completed,
            COUNT(CASE WHEN (expected_amount - discount_amount + surcharge_amount - paid_amount) > 0 AND paid_amount > 0 THEN 1 END) AS partial,
            COUNT(CASE WHEN paid_amount = 0 AND (expected_amount - discount_amount + surcharge_amount - paid_amount) > 0 THEN 1 END) AS unpaid,
            COUNT(CASE WHEN charge_status_id = ? THEN 1 END) AS overdue,
            COALESCE(SUM(CASE WHEN (expected_amount - discount_amount + surcharge_amount - paid_amount) > 0 AND paid_amount > 0 THEN paid_amount ELSE 0 END), 0) AS partial_paid,
            COALESCE(SUM(CASE WHEN paid_amount = 0 THEN (CASE WHEN (expected_amount - discount_amount + surcharge_amount - paid_amount) > 0 THEN (expected_amount - discount_amount + surcharge_amount - paid_amount) ELSE 0 END) ELSE 0 END), 0) AS unpaid_outstanding
            SQL, [ChargeStatus::OVERDUE])->first();

        return response()->json(['data' => [
            'expected' => TenantMoney::make((int) $row->expected),
            'paid' => TenantMoney::make((int) $row->paid),
            'outstanding' => TenantMoney::make((int) $row->outstanding),
            'total' => (int) $row->total,
            'completed' => (int) $row->completed,
            'partial' => (int) $row->partial,
            'unpaid' => (int) $row->unpaid,
            'overdue' => (int) $row->overdue,
            'partial_paid' => TenantMoney::make((int) $row->partial_paid),
            'unpaid_outstanding' => TenantMoney::make((int) $row->unpaid_outstanding),
        ]]);
    }
}
