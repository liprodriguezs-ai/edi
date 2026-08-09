<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\TargetResolver;
use Modules\Collections\Support\MoneyLimit;

/**
 * Charge policy update: same amount constraints as create with `sometimes`.
 *
 * The collection mode is intrinsic to the periodicity (no stored collection_mode column).
 * A periodic (windowed) policy must NOT be switched to a non-automatic periodicity, since
 * that would silently turn a one-shot direct mode loose on a windowed config — the
 * automatic-periodicity contract is re-checked when periodicity_id is touched.
 * The validity window stays editable for periodic policies.
 *
 * SCOPE/PERIODICITY LOCK: `periodicity_id`, `target_type`, `target_selector`, `issued_at`
 * and `due_date` are only editable while the policy has NOT billed yet (no `charge` rows).
 * Once `hasBilled()` is true, those 5 fields are `prohibited` — mirroring the existing
 * `charge_concept_id` immutability — while name/amounts/recargos/valid_from/valid_to stay
 * editable. `ChargePolicyService::update()` applies a defensive second gate.
 */
class ChargePolicyUpdateRequest extends FormRequest
{
    private ?ChargePolicy $resolvedPolicy = null;

    private bool $resolvedPolicyLoaded = false;

    private ?bool $resolvedHasBilled = null;

    public function rules(): array
    {
        $money = ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS];
        $hasBilled = $this->hasBilled();

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Catalog FKs are CENTRAL tables (no tenant_id) → no tenant filter.
            'amount_mode_id' => ['sometimes', 'required', 'uuid', Rule::exists('amount_mode', 'id')],
            // Locked once billed (see class docblock) — mirrors charge_concept_id.prohibited.
            'periodicity_id' => [
                Rule::prohibitedIf($hasBilled),
                'sometimes',
                'required',
                'uuid',
                Rule::exists('periodicity', 'id'),
            ],
            'fixed_amount' => $money,
            'min_amount' => $money,
            'ideal_amount' => $money,
            'max_amount' => $money,
            'prorate_total_amount' => $money,
            'grace_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            // max:60 — a look-ahead window beyond 2 months is operationally unusual;
            // for longer collection cycles the periodicity drives the schedule instead.
            'due_soon_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],

            // Scope of the ROUTED charge_batch (template on periodic / single batch on direct).
            // Locked once billed (see class docblock).
            'target_type' => [Rule::prohibitedIf($hasBilled), 'sometimes', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => [Rule::prohibitedIf($hasBilled), 'sometimes', 'nullable', 'array'],
            'issued_at' => [Rule::prohibitedIf($hasBilled), 'sometimes', 'nullable', 'date'],
            'due_date' => [Rule::prohibitedIf($hasBilled), 'sometimes', 'nullable', 'date', 'after_or_equal:issued_at'],

            // Optional late-fee schedule (recargos moratorios). When present, the array
            // REPLACES the policy's rules (the service syncs create/update/delete).
            'surcharge_rules' => ['sometimes', 'nullable', 'array'],
            'surcharge_rules.*.id' => ['nullable', 'uuid'],
            // No two rules may share the same escalation month — the accrual engine
            // picks ONE rule per from_month (greatest <= monthsOverdue), so duplicates
            // would make the chosen surcharge ambiguous. Mirrors the DB partial unique
            // index charge_surcharge_rule_active_unique.
            'surcharge_rules.*.from_month' => ['required', 'integer', 'min:1', 'max:120', 'distinct'],
            'surcharge_rules.*.surcharge_mode_id' => ['required', 'uuid', Rule::exists('surcharge_mode', 'id')],
            'surcharge_rules.*.surcharge_value' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'surcharge_rules.*.recurrence' => ['required', 'string', Rule::in(ChargeSurchargeRule::RECURRENCES)],
            'surcharge_rules.*.cap_amount' => ['nullable', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'surcharge_rules.*.is_active' => ['nullable', 'boolean'],

            // Immutable / service-owned columns.
            'charge_concept_id' => ['prohibited'],
        ];

        // Idempotency guard mirrored from the DB partial unique index
        // charge_policy_active_unique (tenant_id, charge_concept_id, periodicity_id)
        // WHERE deleted_at IS NULL AND is_active = true. Only relevant while the periodicity
        // is still editable (not billed yet) and the row would remain/become active.
        $policy = $this->policy();
        if (! $hasBilled && $policy && $this->boolean('is_active', (bool) $policy->is_active)) {
            $rules['periodicity_id'][] = Rule::unique('charge_policy')
                ->where('tenant_id', tenant('id'))
                ->where('charge_concept_id', $policy->charge_concept_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->ignore($policy->id);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'valid_to.after_or_equal' => "La fecha 'Hasta' debe ser posterior o igual a 'Vigente desde'.",
            'charge_concept_id.prohibited' => 'El concepto de una política no se puede cambiar.',
            'periodicity_id.prohibited' => 'La periodicidad no se puede cambiar una vez que la política ya generó cobros.',
            'periodicity_id.unique' => 'Ya existe una política activa para este concepto y periodicidad.',
            'periodicity_id.not_in' => 'Esta periodicidad es de pruebas y no está disponible en producción.',
            'target_type.prohibited' => 'El alcance no se puede cambiar una vez que la política ya generó cobros.',
            'target_selector.prohibited' => 'El alcance no se puede cambiar una vez que la política ya generó cobros.',
            'issued_at.prohibited' => 'La fecha de emisión no se puede cambiar una vez que la política ya generó cobros.',
            'due_date.prohibited' => 'La fecha de vencimiento no se puede cambiar una vez que la política ya generó cobros.',
            'surcharge_rules.*.from_month.distinct' => 'No puede haber dos reglas de recargo para el mismo mes de morosidad.',
        ];
    }

    /**
     * Cross-field coherence: RANGE bounds and the automatic-periodicity contract when the
     * periodicity is being changed on a (still periodic) policy.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            ChargeConceptRangeRule::validate($this, $validator);
            $this->validateAutomaticPeriodicity($validator);
            $this->validatePeriodicWindow($validator);
        });
    }

    /**
     * A periodic policy keeps generating charges only while [valid_from, valid_to] covers
     * today (the cron uses whereDate('valid_from', '<=', $today), which never matches NULL).
     * Nulling either bound on a still-periodic policy would leave it is_active=true yet
     * silently inert — reject it with a clear 422 instead of an invisible dead policy.
     */
    private function validatePeriodicWindow(Validator $validator): void
    {
        if (! $this->has('valid_from') && ! $this->has('valid_to')) {
            return;
        }

        $policy = $this->policy();
        if (! $policy || ! $policy->isPeriodic()) {
            return;
        }

        if ($this->has('valid_from') && $this->input('valid_from') === null) {
            $validator->errors()->add(
                'valid_from',
                'Una política periódica requiere fecha de inicio de vigencia.',
            );
        }

        if ($this->has('valid_to') && $this->input('valid_to') === null) {
            $validator->errors()->add(
                'valid_to',
                'Una política periódica requiere fecha de fin de vigencia.',
            );
        }
    }

    private function validateAutomaticPeriodicity(Validator $validator): void
    {
        $periodicityId = $this->input('periodicity_id');
        if (! $periodicityId) {
            return;
        }

        $policy = $this->policy();
        if (! $policy || ! $policy->isPeriodic()) {
            return;
        }

        $periodicity = Periodicity::find($periodicityId);
        if ($periodicity && ! $periodicity->is_automatic) {
            $validator->errors()->add(
                'periodicity_id',
                'Un cobro periódico requiere una periodicidad automática.',
            );
        }
    }

    /**
     * Memoized route-resolved policy — reused by rules()/withValidator() helpers so a
     * single request only resolves + queries it once.
     */
    private function policy(): ?ChargePolicy
    {
        if (! $this->resolvedPolicyLoaded) {
            $this->resolvedPolicy = ChargePolicy::find($this->route('charge_policy') ?? $this->route('id'));
            $this->resolvedPolicyLoaded = true;
        }

        return $this->resolvedPolicy;
    }

    /**
     * Whether this policy has ever billed (owns at least one `charge` row). Memoized:
     * `rules()` evaluates it for 5 separate `prohibitedIf` closures.
     */
    private function hasBilled(): bool
    {
        if ($this->resolvedHasBilled === null) {
            $this->resolvedHasBilled = (bool) $this->policy()?->charges()->exists();
        }

        return $this->resolvedHasBilled;
    }

    public function authorize(): bool
    {
        return true;
    }
}
