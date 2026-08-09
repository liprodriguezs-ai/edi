<?php

namespace Modules\Collections\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Collections\Exceptions\DelinquencyRestrictedException;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Housing\Models\Unit;
use Modules\Setting\Models\Setting;

/**
 * DelinquencyRestrictionService — the single source of truth for whether a
 * unit is blocked from a guarded feature because of unpaid debt (R-DELQ-1..4).
 *
 * A unit is delinquent ONLY when `charge_status_id === ChargeStatus::OVERDUE`
 * (never PARTIAL — that means "paid something, still within term") AND its
 * overdue balance is >= `delinquency.min_overdue_amount`. The master switch
 * `delinquency.restrictions_enabled` and each per-feature toggle both gate
 * independently on top of that criterion.
 *
 * Registered with `$this->app->scoped(...)` in CollectionsServiceProvider —
 * one instance per request so the settings() memoization actually holds for
 * the whole request, without pinning one tenant's settings across Octane
 * requests the way `singleton()` would.
 */
final class DelinquencyRestrictionService
{
    public const FEATURE_PACKAGE_INTAKE = 'package_intake';

    public const FEATURE_AMENITIES = 'amenities';

    public const FEATURE_VISITOR_ACCESS = 'visitor_access';

    /**
     * Keyed by tenant id, NOT a single flat cache (tenancy-reviewer WARNING).
     * `scoped()` correctly flushes this instance between HTTP requests and
     * queue jobs (verified: Octane's FlushTemporaryContainerInstances on
     * OperationTerminated; QueueServiceProvider's per-job resetScope), but a
     * console command that loops `Tenant::all()` + `$tenant->run()` while
     * holding ONE resolved instance (e.g. constructor/method injection before
     * the loop) would otherwise see `scoped()` behave exactly like
     * `singleton()` — one $settingsCache for every tenant in the run. Keying
     * by the current tenant makes this correct regardless of how long the
     * container holds the instance, instead of depending on the binding
     * lifecycle for correctness.
     *
     * @var array<string, array<string, bool|int|string>>
     */
    private array $settingsCache = [];

    public function __construct(private readonly ChargeStatusService $chargeStatusService) {}

    /**
     * Overdue balance for one unit, minor units. Sum of each OVERDUE
     * non-cancelled charge's effective-expected minus what has been paid,
     * floored at 0 per charge.
     */
    public function overdueBalanceFor(string $unitId): int
    {
        return $this->overdueBalancesByUnit([$unitId])[$unitId] ?? 0;
    }

    /**
     * The single blocking rule for one unit/feature pair.
     */
    public function isRestricted(string $unitId, string $feature): bool
    {
        return $this->evaluate($unitId, $feature)['restricted'];
    }

    /**
     * @throws DelinquencyRestrictedException (403) when the unit is restricted for $feature.
     */
    public function assertNotRestricted(string $unitId, string $feature): void
    {
        $result = $this->evaluate($unitId, $feature);

        if (! $result['restricted']) {
            return;
        }

        if ($feature === self::FEATURE_PACKAGE_INTAKE) {
            throw DelinquencyRestrictedException::forGuard();
        }

        // A min_overdue_amount = 0 check skips the balance query (see evaluate());
        // resolve it now since the resident-facing payload always needs the amount.
        $overdueBalanceMinor = $result['balance'] ?? $this->overdueBalanceFor($unitId);

        throw DelinquencyRestrictedException::forResident(
            $overdueBalanceMinor,
            (string) $this->settings()[DelinquencySettings::NOTICE_TEXT],
        );
    }

    /**
     * PER-UNIT payload for GET /my-restrictions — never an ANY-aggregate. A
     * resident can hold unit A (current) and unit B (OVERDUE); an aggregate
     * would incorrectly restrict A. Returns one entry per requested unit id,
     * `overdue_balance` (raw minor units — the caller converts to Money at
     * the response boundary) present only when that unit is restricted.
     *
     * @param  array<int, string>  $unitIds
     * @return array<int, array{unit_id: string, restricted: bool, features: array{amenities: bool, visitor_access: bool}, overdue_balance?: int}>
     */
    public function stateForUnits(array $unitIds): array
    {
        $unitIds = array_values(array_unique(array_filter($unitIds)));

        if ($unitIds === []) {
            return [];
        }

        $settings = $this->settings();

        if (! $settings[DelinquencySettings::RESTRICTIONS_ENABLED]) {
            return array_map(fn (string $unitId): array => $this->unrestrictedEntry($unitId), $unitIds);
        }

        $overdueUnitIds = Unit::query()
            ->whereIn('id', $unitIds)
            ->where('charge_status_id', ChargeStatus::OVERDUE)
            ->pluck('id')
            ->all();

        $minOverdueAmount = (int) $settings[DelinquencySettings::MIN_OVERDUE_AMOUNT];
        $balances = $overdueUnitIds === [] ? [] : $this->overdueBalancesByUnit($overdueUnitIds);

        return array_map(function (string $unitId) use ($overdueUnitIds, $balances, $minOverdueAmount, $settings): array {
            if (! in_array($unitId, $overdueUnitIds, true)) {
                return $this->unrestrictedEntry($unitId);
            }

            $balance = $balances[$unitId] ?? 0;
            $overThreshold = $minOverdueAmount === 0 || $balance >= $minOverdueAmount;

            $amenitiesRestricted = $overThreshold && (bool) $settings[DelinquencySettings::BLOCK_AMENITIES];
            $visitorAccessRestricted = $overThreshold && (bool) $settings[DelinquencySettings::BLOCK_VISITOR_ACCESS];
            $restricted = $amenitiesRestricted || $visitorAccessRestricted;

            $entry = [
                'unit_id' => $unitId,
                'restricted' => $restricted,
                'features' => [
                    'amenities' => $amenitiesRestricted,
                    'visitor_access' => $visitorAccessRestricted,
                ],
            ];

            if ($restricted) {
                $entry['overdue_balance'] = $balance;
            }

            return $entry;
        }, $unitIds);
    }

    /**
     * Unit ids currently restricted for $feature — batch, N+1-proof (R-DELQ-7
     * backing). At most 3 queries regardless of how many units are OVERDUE.
     *
     * @return array<int, string>
     */
    public function restrictedUnitIds(string $feature): array
    {
        $settings = $this->settings();

        if (! $settings[DelinquencySettings::RESTRICTIONS_ENABLED] || ! $settings[$this->blockSlugFor($feature)]) {
            return [];
        }

        $unitIds = Unit::query()
            ->where('charge_status_id', ChargeStatus::OVERDUE)
            ->pluck('id')
            ->all();

        if ($unitIds === []) {
            return [];
        }

        $minOverdueAmount = (int) $settings[DelinquencySettings::MIN_OVERDUE_AMOUNT];

        if ($minOverdueAmount === 0) {
            return $unitIds;
        }

        $balances = $this->overdueBalancesByUnit($unitIds);

        return array_values(array_filter(
            $unitIds,
            fn (string $unitId): bool => ($balances[$unitId] ?? 0) >= $minOverdueAmount,
        ));
    }

    /**
     * The resident-facing notice text (`delinquency.notice_text`). Exposed so a
     * caller that already called `stateForUnits()`/`restrictedUnitIds()`/
     * `isRestricted()` on THIS instance (e.g. GET /my-restrictions right after
     * `stateForUnits()`) reuses the settings() memoization instead of issuing a
     * redundant `Setting::getValue()` SELECT (infra-cost finding).
     */
    public function noticeText(): string
    {
        return (string) $this->settings()[DelinquencySettings::NOTICE_TEXT];
    }

    /**
     * Evaluate the blocking rule for one unit/feature pair, returning the
     * balance alongside the verdict when it was already computed (min > 0),
     * so assertNotRestricted() does not always re-query for the amount.
     *
     * @return array{restricted: bool, balance: int|null}
     */
    private function evaluate(string $unitId, string $feature): array
    {
        $settings = $this->settings();

        if (! $settings[DelinquencySettings::RESTRICTIONS_ENABLED]) {
            return ['restricted' => false, 'balance' => null];
        }

        if (! $settings[$this->blockSlugFor($feature)]) {
            return ['restricted' => false, 'balance' => null];
        }

        $chargeStatusId = Unit::query()->where('id', $unitId)->value('charge_status_id');

        if ($chargeStatusId !== ChargeStatus::OVERDUE) {
            return ['restricted' => false, 'balance' => null];
        }

        $minOverdueAmount = (int) $settings[DelinquencySettings::MIN_OVERDUE_AMOUNT];

        if ($minOverdueAmount === 0) {
            // Any OVERDUE unit is restricted — skip the balance query entirely.
            return ['restricted' => true, 'balance' => null];
        }

        $balance = $this->overdueBalanceFor($unitId);

        return ['restricted' => $balance >= $minOverdueAmount, 'balance' => $balance];
    }

    /**
     * Grouped overdue balance per unit id — ONE query loading every OVERDUE
     * non-cancelled charge for the given units, summed in PHP via
     * ChargeStatusService::effectiveExpected() (never a 5th reimplementation
     * of the outstanding formula).
     *
     * @param  array<int, string>  $unitIds
     * @return array<string, int>
     */
    private function overdueBalancesByUnit(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return Charge::query()
            ->whereIn('unit_id', $unitIds)
            ->where('charge_status_id', ChargeStatus::OVERDUE)
            ->where('is_cancelled', false)
            ->get(['unit_id', 'id', 'expected_amount', 'discount_amount', 'surcharge_amount', 'paid_amount'])
            ->groupBy('unit_id')
            ->map(fn (Collection $charges): int => $charges->sum(
                fn (Charge $charge): int => max(0, $this->chargeStatusService->effectiveExpected($charge)
                    - (int) $charge->getAttributes()['paid_amount'])
            ))
            ->all();
    }

    /**
     * @return array{unit_id: string, restricted: false, features: array{amenities: false, visitor_access: false}}
     */
    private function unrestrictedEntry(string $unitId): array
    {
        return [
            'unit_id' => $unitId,
            'restricted' => false,
            'features' => [
                'amenities' => false,
                'visitor_access' => false,
            ],
        ];
    }

    private function blockSlugFor(string $feature): string
    {
        return match ($feature) {
            self::FEATURE_AMENITIES => DelinquencySettings::BLOCK_AMENITIES,
            self::FEATURE_VISITOR_ACCESS => DelinquencySettings::BLOCK_VISITOR_ACCESS,
            self::FEATURE_PACKAGE_INTAKE => DelinquencySettings::BLOCK_PACKAGE_INTAKE,
            default => throw new InvalidArgumentException("Unknown delinquency feature: {$feature}"),
        };
    }

    /**
     * Memoized per instance AND per tenant (see the property docblock — keying
     * by tenant makes this correct regardless of how long the container holds
     * the instance, instead of depending on scoped()'s flush lifecycle). ONE
     * Setting::whereIn over the six slugs per tenant; a row absent for this
     * tenant falls back to its documented default.
     *
     * Deliberately NO QueryException catch here (sdd-verify finding W-1),
     * unlike ChargeStatusService::resolveThresholds()'s table-absent fallback.
     * That precedent is safe because its default (grace days / overpayment %)
     * is a benign, semantically correct value to compute a status with. Here
     * every default is DISABLED — a caught failure would silently turn OFF an
     * admin-enabled restriction tenant-wide, with no signal. And a genuinely
     * unavailable `setting` table is not a benign, isolated condition: the
     * next query this request makes (Unit/Charge lookups) fails too, so
     * swallowing it here does not actually protect anything — it only masks
     * the real fault behind an incorrect "not restricted" answer. Let it
     * surface as a 500 like every other unhandled query failure in the app.
     *
     * @return array<string, bool|int|string>
     */
    private function settings(): array
    {
        $tenantKey = tenancy()->initialized ? (string) tenant()->getTenantKey() : '';

        if (isset($this->settingsCache[$tenantKey])) {
            return $this->settingsCache[$tenantKey];
        }

        $defaults = DelinquencySettings::all();
        $rows = Setting::query()->whereIn('slug', array_keys($defaults))->get()->keyBy('slug');

        $resolved = [];
        foreach ($defaults as $slug => $default) {
            $resolved[$slug] = $rows->has($slug) ? $rows->get($slug)->resolved_value : $default;
        }

        return $this->settingsCache[$tenantKey] = $resolved;
    }
}
