<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Periodicity;
use Modules\Tenant\Models\Tenant;

/**
 * Shared charge-config builders for tests after the concept → policy split.
 *
 * A charge_concept now holds only IDENTITY (code/name/category); the charge
 * BEHAVIOR (amount mode/amounts, grace, periodicity, validity) lives on a sibling
 * charge_policy — the collection mode is intrinsic to the periodicity (EVENTUAL =
 * direct one-shot; any automatic periodicity = recurrent). These helpers create the
 * pair and return the
 * ChargePolicy — which exposes amount_mode_id / periodicity_id / charge_concept_id
 * and a concept() relation, so call sites that used to read those off the concept
 * read them off the policy instead.
 *
 * Usage: `use Tests\Support\WithChargeConfig;` in the class body.
 */
trait WithChargeConfig
{
    /**
     * Build a concept + policy and return the policy. Must run inside tenant context.
     *
     * @param  array<string, mixed>  $policyAttributes  extra/override policy attributes
     */
    protected function makeChargePolicyInTenant(
        string $amountModeId = AmountMode::FIXED_REQUIRED,
        string $periodicityId = Periodicity::MONTHLY,
        array $policyAttributes = [],
        string $categoryName = 'Ordinaria',
        ?string $code = null,
        string $conceptName = 'Concepto de prueba',
    ): ChargePolicy {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, $categoryName));

        $concept = ChargeConcept::create([
            'code' => $code ?? 'TEST_'.mt_rand(1000, 999999),
            'name' => $conceptName,
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        // The collection mode is intrinsic to the periodicity: EVENTUAL (the only
        // non-automatic periodicity) is a one-shot DIRECT policy with no validity window;
        // any automatic periodicity is recurrent PERIODIC and carries a window.
        $isDirect = $periodicityId === Periodicity::EVENTUAL;

        $defaults = [
            'charge_concept_id' => $concept->id,
            'name' => 'Política '.$conceptName,
            'amount_mode_id' => $amountModeId,
            'periodicity_id' => $periodicityId,
            'valid_from' => $isDirect ? null : '2026-01-01',
            'valid_to' => null,
            'grace_days' => 0,
            'is_active' => true,
        ];

        return ChargePolicy::create(array_merge($defaults, $policyAttributes));
    }

    /**
     * Convenience: build a concept + policy in the given tenant's scope.
     *
     * @param  array<string, mixed>  $policyAttributes
     */
    protected function makeChargePolicy(
        Tenant $tenant,
        string $amountModeId = AmountMode::FIXED_REQUIRED,
        string $periodicityId = Periodicity::MONTHLY,
        array $policyAttributes = [],
        string $categoryName = 'Ordinaria',
        ?string $code = null,
        string $conceptName = 'Concepto de prueba',
    ): ChargePolicy {
        $policy = null;
        $tenant->run(function () use (&$policy, $amountModeId, $periodicityId, $policyAttributes, $categoryName, $code, $conceptName): void {
            $policy = $this->makeChargePolicyInTenant($amountModeId, $periodicityId, $policyAttributes, $categoryName, $code, $conceptName);
        });

        return $policy;
    }
}
