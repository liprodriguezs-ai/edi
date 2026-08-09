<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Collections\Http\Controllers\Concerns\ResolvesPaymentMethodRules;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Observers\StatementTotalsCacheObserver;
use Modules\Collections\Services\CollectionsReportService;
use Modules\Collections\Services\PaymentService;
use Modules\Collections\Support\MoneyLimit;
use Modules\Collections\Support\PaymentEarmarkRegistry;
use Modules\Housing\Models\UnitUser;
use Modules\Treasury\Models\BankAccount;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Http\Controllers\ReportExport;
use QCH\Core\Support\TenantMoney;

class PaymentController extends Controller
{
    use ResolvesPaymentMethodRules;

    protected $model = Payment::class;

    protected $keyForPermission = 'payment';

    public function includes(): array
    {
        return [
            'unit', 'unit.unitType', 'unit.tower',
            'payment_status', 'payment_method', 'bank_account',
            'applications', 'applications.charge', 'applications.charge.concept', 'applications.charge.batch',
            'payer_user', 'media',
        ];
    }

    /**
     * Authorize a payment action.
     *
     * Orion's show() resolves the (tenant-scoped) Payment first, then calls
     * authorize('read.payment', $payment). Board/admin carry the broad read.payment
     * ability and may read ANY payment of the cluster. A resident WITHOUT that
     * ability may still read a payment that belongs to one of THEIR ACTIVE units —
     * this is the destination of the collection deep-links (payment_accepted /
     * payment_registered_direct). Ownership is checked against the unit_user pivot
     * (active links only, end_date IS NULL), mirroring statement()/submit().
     *
     * Only the SINGLE-record show path is relaxed: it is the only call that passes a
     * Payment instance. index/search/delinquency pass the model CLASS, so they keep
     * the strict broad-ability check via parent::authorize(). Cross-tenant access is
     * impossible: the Payment was already fetched through Payment's BelongsToTenant
     * scope (foreign-tenant → 404 before this runs), and UnitUser is tenant-scoped.
     *
     * @param  mixed  $ability
     * @param  mixed|array  $arguments
     */
    public function authorize($ability, $arguments = [])
    {
        if ($ability === 'read.'.$this->keyForPermission && $arguments instanceof Payment) {
            $user = auth()->guard('sanctum')->user();
            $hasBroadRead = $user && ($user->tokenCan($ability) || $user->tokenCan('*'));

            if (! $hasBroadRead) {
                $ownsUnit = $user && UnitUser::where('unit_id', $arguments->unit_id)
                    ->where('user_id', $user->id)
                    ->whereNull('end_date')
                    ->exists();

                if (! $ownsUnit) {
                    throw new AuthorizationException('Unauthorized action.');
                }

                return $this->authorized();
            }
        }

        return parent::authorize($ability, $arguments);
    }

    public function searchableBy(): array
    {
        return ['receipt_number', 'reference', 'physical_receipt_number'];
    }

    public function sortableBy(): array
    {
        return ['paid_at', 'total_amount', 'receipt_number'];
    }

    public function filterableBy(): array
    {
        return [
            'unit_id',
            'payment_status_id',
            'payment_method_id',
            'bank_account_id',
            'total_amount',
            'paid_at',
            'earmark_type',
            'earmark_id',
        ];
    }

    /**
     * POST payment/register — register a payment via PaymentService.
     * This custom endpoint delegates entirely to the service for transactional safety.
     */
    public function register(Request $request): JsonResponse
    {
        $this->authorize('create.payment', Payment::class);

        $validated = $request->validate([
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'paid_at' => ['required', 'date'],
            // §18.7.4: amounts are positive and < $10,000,000 MXN (minor units cap).
            'total_amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'payment_method_id' => ['required', 'uuid'],
            'bank_account_id' => ['required', 'uuid', Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'payer_user_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:255'],
            'physical_receipt_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'manual_applications' => ['nullable', 'array'],
            'manual_applications.*.charge_id' => ['required_with:manual_applications', 'uuid'],
            'manual_applications.*.apply_amount' => ['required_with:manual_applications', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'earmark_type' => ['nullable', 'string', 'required_with:earmark_id', Rule::in(app(PaymentEarmarkRegistry::class)->keys())],
            'earmark_id' => ['required_with:earmark_type', 'uuid'],
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

        $service = app(PaymentService::class);

        try {
            $payment = $service->register($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->hasFile('receipt')) {
            $payment->addMedia($request->file('receipt'))->toMediaCollection('receipt');
        }

        return response()->json(['data' => $payment->load('media')], 201);
    }

    /**
     * POST payment/submit — RESIDENT self-captures a payment for their OWN unit (spec §8.5.2).
     *
     * Creates a PENDING_VALIDATION payment, attaches the comprobante (required for non-cash),
     * and does NOT apply to charges or touch the bank balance. Authorized to any member of
     * the unit via the unit_user pivot (same ownership check as statement).
     */
    public function submit(Request $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        // The caller needs the scoped submission ability (residents/renters carry it).
        // Admin wildcard also passes. Treasurer/admin who capture for OTHER units use
        // payment/register instead — submit is strictly self-capture for the OWN unit.
        if (! $user || ! ($user->tokenCan('create.payment_submission') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $validated = $request->validate([
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'paid_at' => ['required', 'date'],
            // §18.7.4: amounts are positive and < $10,000,000 MXN (minor units cap).
            'total_amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'payment_method_id' => ['required', 'uuid'],
            // §8.5.2: the resident does NOT pick the destination account — it is a treasury
            // concern. When provided it must be a valid cluster account; when absent the
            // cluster's primary active account is assigned by default (resolved below).
            'bank_account_id' => ['nullable', 'uuid', Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'earmark_type' => ['nullable', 'string', 'required_with:earmark_id', Rule::in(app(PaymentEarmarkRegistry::class)->keys())],
            'earmark_id' => ['required_with:earmark_type', 'uuid'],
        ]);

        // Authorize: the caller must be a member of the unit (owner/renter/etc).
        $belongsToUnit = UnitUser::where('unit_id', $validated['unit_id'])
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->exists();

        if (! $belongsToUnit) {
            throw new AuthorizationException('Unauthorized action.');
        }

        // §8.5.2: residents don't choose the destination account. Default to the cluster's
        // primary active account (same criterion as getResidentBankData). The payment column
        // is NOT NULL, so the pending payment lands on the primary account; the tesorero may
        // reassign it on validation (follow-up). BankAccount is tenant-scoped, so this only
        // ever resolves an account of the resident's own cluster.
        if (empty($validated['bank_account_id'])) {
            $primaryAccount = BankAccount::where('is_active', true)
                ->orderByDesc('is_primary')
                ->first();

            if (! $primaryAccount) {
                return response()->json([
                    'message' => 'El condominio no tiene una cuenta bancaria configurada.',
                ], 422);
            }

            $validated['bank_account_id'] = $primaryAccount->id;
        }

        // §16.3.4: a payment method disabled in cluster configuration cannot be used.
        if (! $this->paymentMethodIsEnabled($validated['payment_method_id'])) {
            return response()->json([
                'message' => 'El método de pago seleccionado no está habilitado en la configuración del condominio.',
            ], 422);
        }

        // §8.7.1 r5/r7: a resident-captured non-cash payment REQUIRES a comprobante.
        if ($this->requiresReceipt($validated['payment_method_id']) && ! $request->hasFile('receipt')) {
            return response()->json([
                'message' => 'El comprobante es obligatorio para pagos que no son en efectivo.',
            ], 422);
        }

        $validated['payer_user_id'] = $user->id;

        try {
            $payment = app(PaymentService::class)->submit($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->hasFile('receipt')) {
            $payment->addMedia($request->file('receipt'))->toMediaCollection('receipt');
        }

        return response()->json(['data' => $payment->load('media')], 201);
    }

    /**
     * POST payment/{id}/validate — Tesorero/Admin validates a pending payment (spec §8.5.3):
     * status → CONFIRMED, FIFO applied, bank incremented, receipt_number generated.
     */
    public function validatePayment(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.payment', Payment::class);

        $payment = Payment::findOrFail($id);

        try {
            $confirmed = app(PaymentService::class)->validatePayment($payment);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $confirmed]);
    }

    /**
     * POST payment/{id}/reject — Tesorero/Admin rejects a pending payment (spec §8.5.3):
     * status → CANCELLED with a reason. No bank/charge mutation.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.payment', Payment::class);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5']])['reason'];

        $payment = Payment::findOrFail($id);

        try {
            $rejected = app(PaymentService::class)->reject($payment, $reason);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $rejected]);
    }

    /**
     * POST payment/{id}/cancel — cancel a payment and reverse applications.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.payment', Payment::class);

        $request->validate(['reason' => ['required', 'string', 'min:5']]);

        $payment = Payment::findOrFail($id);
        $service = app(PaymentService::class);
        $payment = $service->cancel($payment, $request->input('reason'));

        return response()->json(['data' => $payment]);
    }

    /**
     * GET unit/{id}/statement — account statement for a unit.
     *
     * Board/admin (broad read.payment ability) may read any unit's statement.
     * A resident WITHOUT that ability may only read a statement for a unit they
     * belong to (checked via the unit_user pivot for the authenticated user).
     */
    public function statement(Request $request, string $unitId): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $hasBoardReadAccess = $user && ($user->tokenCan('read.payment') || $user->tokenCan('*'));

        if (! $hasBoardReadAccess) {
            $belongsToUnit = $user && UnitUser::where('unit_id', $unitId)
                ->where('user_id', $user->id)
                ->whereNull('end_date')
                ->exists();

            if (! $belongsToUnit) {
                throw new AuthorizationException('Unauthorized action.');
            }
        }

        // Pending charges only: non-cancelled with a positive principal balance
        // (expected - discount - paid > 0) — the exact set both payment sheets consume
        // (chargeOutstanding). The fully-paid history is no longer shipped here; the
        // paginated /charge list renders it. Always fresh (few rows), never cached.
        $charges = Charge::query()
            ->where('unit_id', $unitId)
            ->where('is_cancelled', false)
            ->whereRaw('(expected_amount - COALESCE(discount_amount, 0) - paid_amount) > 0')
            ->with(['concept', 'charge_status', 'batch', 'payment_applications'])
            ->orderBy('due_date')
            ->get();

        $aggregate = $this->cachedStatementAggregate($unitId);

        return response()->json([
            'unit_id' => $unitId,
            'charges' => $charges,
            'credit_balance' => TenantMoney::make($aggregate['credit_balance_minor_units']),
            'totals' => [
                'expected' => TenantMoney::make($aggregate['totals']['expected_minor_units']),
                'discount' => TenantMoney::make($aggregate['totals']['discount_minor_units']),
                'surcharge' => TenantMoney::make($aggregate['totals']['surcharge_minor_units']),
                'paid' => TenantMoney::make($aggregate['totals']['paid_minor_units']),
                'outstanding' => TenantMoney::make($aggregate['totals']['outstanding_minor_units']),
            ],
        ]);
    }

    /**
     * Compute (and cache) a unit's statement totals + credit balance — the expensive aggregate
     * over ALL of the unit's open charges. Cached per tenant+unit under
     * statement_totals:{tenant_id}:{unit_id}; StatementTotalsCacheObserver forgets the key on
     * any Charge/CreditBalance write. The pending `charges` list is NOT cached (it is cheap and
     * must stay fresh for the payment sheets). Gated by config so it can be disabled.
     *
     * @return array{
     *     totals: array{expected_minor_units: int, discount_minor_units: int, surcharge_minor_units: int, paid_minor_units: int, outstanding_minor_units: int},
     *     credit_balance_minor_units: int
     * }
     */
    private function cachedStatementAggregate(string $unitId): array
    {
        $compute = function () use ($unitId): array {
            // Outstanding uses the EFFECTIVE expected (max(0, expected - discount) + surcharge)
            // so accrued late fees count toward the unit's debt; the expected total keeps the
            // original expected so a condonacion shows as a reduction. Parity with delinquency()
            // and ChargeStatusService::effectiveExpected().
            $openCharges = Charge::query()
                ->where('unit_id', $unitId)
                ->where('is_cancelled', false)
                ->get(['expected_amount', 'discount_amount', 'surcharge_amount', 'paid_amount']);

            $totalExpected = $openCharges->sum(fn ($c) => (int) $c->getAttributes()['expected_amount']);
            $totalDiscount = $openCharges->sum(fn ($c) => (int) ($c->getAttributes()['discount_amount'] ?? 0));
            $totalSurcharge = $openCharges->sum(fn ($c) => (int) ($c->getAttributes()['surcharge_amount'] ?? 0));
            $totalPaid = $openCharges->sum(fn ($c) => (int) $c->getAttributes()['paid_amount']);
            $totalOutstanding = $openCharges->sum(fn ($c) => max(
                0,
                max(
                    0,
                    (int) $c->getAttributes()['expected_amount']
                        - (int) ($c->getAttributes()['discount_amount'] ?? 0),
                )
                    + (int) ($c->getAttributes()['surcharge_amount'] ?? 0)
                    - (int) $c->getAttributes()['paid_amount'],
            ));

            $creditBalance = CreditBalance::where('unit_id', $unitId)->first();
            $creditRaw = $creditBalance ? (int) $creditBalance->getAttributes()['amount'] : 0;

            return [
                'totals' => [
                    'expected_minor_units' => $totalExpected,
                    'discount_minor_units' => $totalDiscount,
                    'surcharge_minor_units' => $totalSurcharge,
                    'paid_minor_units' => $totalPaid,
                    'outstanding_minor_units' => $totalOutstanding,
                ],
                'credit_balance_minor_units' => $creditRaw,
            ];
        };

        if (! config('collections.statement_cache.enabled', true)) {
            return $compute();
        }

        $tenantId = tenancy()->initialized ? (string) tenancy()->tenant->getTenantKey() : 'no_tenant';
        $ttl = (int) config('collections.statement_cache.ttl', 3600);

        return Cache::remember(
            StatementTotalsCacheObserver::keyFor($tenantId, $unitId),
            $ttl,
            $compute,
        );
    }

    /**
     * Validate and normalize the delinquency filters shared by the JSON dashboard
     * (delinquency) and the xlsx export (delinquencyReport). Amounts arrive as
     * integer MINOR UNITS (the UI converts pesos before sending, same contract as
     * every other money field on this API).
     *
     * No cross-field min/max rule on purpose: min > max simply yields an empty
     * result, consistent with the Orion range filters on /charge.
     *
     * @return array{
     *     search: string|null,
     *     charge_concept_ids: array<int, string>,
     *     min_months_overdue: int|null,
     *     min_amount: int|null,
     *     max_amount: int|null
     * }
     */
    private function validateDelinquencyFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'charge_concept_ids' => ['nullable', 'array', 'max:50'],
            'charge_concept_ids.*' => ['uuid', Rule::exists('charge_concept', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'min_months_overdue' => ['nullable', 'integer', 'min:1', 'max:120'],
            'min_amount' => ['nullable', 'integer', 'min:0', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'max_amount' => ['nullable', 'integer', 'min:0', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        return [
            'search' => $search !== '' ? $search : null,
            'charge_concept_ids' => $validated['charge_concept_ids'] ?? [],
            'min_months_overdue' => isset($validated['min_months_overdue']) ? (int) $validated['min_months_overdue'] : null,
            'min_amount' => isset($validated['min_amount']) ? (int) $validated['min_amount'] : null,
            'max_amount' => isset($validated['max_amount']) ? (int) $validated['max_amount'] : null,
        ];
    }

    /**
     * GET delinquency — dashboard data: units with overdue charges.
     *
     * Accepts the shared delinquency filters (search, charge_concept_ids,
     * min_months_overdue, min_amount/max_amount). The aggregation itself lives
     * in CollectionsReportService::delinquencyByUnit (single source shared with
     * the xlsx export). The KPI aggregates (units_with_debt / total_outstanding)
     * reflect the FILTERED set — they answer "cuánto se debe de lo que estoy viendo".
     */
    public function delinquency(Request $request): JsonResponse
    {
        $this->authorize('read.payment', Payment::class);

        $byUnit = app(CollectionsReportService::class)
            ->delinquencyByUnit($this->validateDelinquencyFilters($request));

        $totalOutstandingMinorUnits = $byUnit->sum('outstanding_minor_units');

        $data = $byUnit->map(function (array $row): array {
            return [
                'unit_id' => $row['unit_id'],
                'unit_number' => $row['unit_number'],
                'unit_full_identifier' => $row['unit_full_identifier'],
                'outstanding' => TenantMoney::make($row['outstanding_minor_units']),
                'oldest_due_date' => $row['oldest_due_date'],
                'charge_count' => $row['charge_count'],
            ];
        });

        return response()->json([
            'units_with_debt' => $byUnit->count(),
            'total_outstanding' => TenantMoney::make($totalOutstandingMinorUnits),
            'data' => $data,
        ]);
    }

    /**
     * GET delinquency/report — xlsx export of the delinquency dashboard.
     *
     * Mirrors the on-screen table (Unidad / Cargos vencidos / Adeudo más antiguo /
     * Adeudo vencido) and reuses delinquencyRows() so the export can NEVER drift
     * from the JSON endpoint — including the ACTIVE FILTERS: the file contains
     * exactly what the dashboard shows. Gated by report.payment, consistent with
     * every other "Descargar" list export (export-excel-lists convention).
     */
    public function delinquencyReport(Request $request): mixed
    {
        $this->authorize('report.payment', Payment::class);

        $rows = app(CollectionsReportService::class)
            ->delinquencyByUnit($this->validateDelinquencyFilters($request));

        $export = app(ReportExport::class);
        $export->setTitle('Morosidad');
        $export->setHeadings(['Unidad', 'Cargos vencidos', 'Adeudo más antiguo', 'Adeudo vencido']);
        $export->setMap([
            'Unidad' => function (array $row): string {
                return $row['unit_full_identifier'] ?? $row['unit_number'] ?? '';
            },
            'Cargos vencidos' => function (array $row): int {
                return $row['charge_count'];
            },
            'Adeudo más antiguo' => function (array $row): string {
                return $row['oldest_due_date']?->format('d/m/Y') ?? '';
            },
            // Money in xlsx follows the project rule: (float) Money->formatByDecimal(),
            // NEVER /100 nor raw minor units in the cell.
            'Adeudo vencido' => function (array $row): float {
                return (float) TenantMoney::make((int) $row['outstanding_minor_units'])->formatByDecimal();
            },
        ]);
        $export->setCollection($rows);

        return Excel::download($export, 'morosidad.xlsx');
    }
}
