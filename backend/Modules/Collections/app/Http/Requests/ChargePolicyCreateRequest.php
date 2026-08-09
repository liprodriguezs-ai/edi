<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\TargetResolver;
use Modules\Collections\Support\MoneyLimit;

/**
 * Charge policy creation: the single point of charge configuration for a concept.
 *
 * Amounts are required according to the amount mode (mirrors the old ChargeConcept
 * rules). The collection MODE is intrinsic to the periodicity — there is no separate
 * collection_mode field — and it drives the validity contract:
 *  - PERIODIC (automatic periodicity): valid_from + valid_to REQUIRED (active window) so the
 *    cron can fire it.
 *  - DIRECT (EVENTUAL periodicity, is_automatic = false): valid_from/valid_to are PROHIBITED.
 *
 * Both modes are configured 1-to-1 with their INITIAL (template) batch, so target_type is
 * REQUIRED for both. issued_at/due_date are REQUIRED for direct (no schedule to derive from)
 * and OPTIONAL for periodic (the first period is derived from valid_from when omitted).
 */
class ChargePolicyCreateRequest extends FormRequest
{
    public function rules(): array
    {
        $money = ['nullable', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS];

        // The collection mode is intrinsic to the periodicity: EVENTUAL (the only
        // non-automatic periodicity) is a one-shot DIRECT policy; any other periodicity
        // is recurrent PERIODIC. No collection_mode field is captured.
        $isDirect = $this->input('periodicity_id') === Periodicity::EVENTUAL;

        $rules = [
            // charge_concept is TENANT-scoped → exists must filter by tenant.
            'charge_concept_id' => ['required', 'uuid', Rule::exists('charge_concept', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            // Catalog FKs are CENTRAL tables (no tenant_id) → no tenant filter.
            'amount_mode_id' => ['required', 'uuid', Rule::exists('amount_mode', 'id')],
            'periodicity_id' => ['required', 'uuid', Rule::exists('periodicity', 'id')],
            'fixed_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->amountModeIs(AmountMode::FIXED_REQUIRED, AmountMode::FIXED_OPTIONAL))],
            'min_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->amountModeIs(AmountMode::RANGE))],
            'ideal_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->amountModeIs(AmountMode::RANGE))],
            'max_amount' => $money,
            'prorate_total_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->amountModeIs(AmountMode::BY_SHARE))],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // max:60 — a look-ahead window beyond 2 months is operationally unusual;
            // for longer collection cycles the periodicity drives the schedule instead.
            'due_soon_days' => ['nullable', 'integer', 'min:0', 'max:60'],

            // periodic: window required. direct (EVENTUAL): window prohibited.
            'valid_from' => [Rule::requiredIf(fn (): bool => ! $isDirect), 'prohibited_if:periodicity_id,'.Periodicity::EVENTUAL, 'date'],
            'valid_to' => [Rule::requiredIf(fn (): bool => ! $isDirect), 'prohibited_if:periodicity_id,'.Periodicity::EVENTUAL, 'date', 'after_or_equal:valid_from'],

            // Initial (template) batch parameters. target_type is REQUIRED for BOTH modes
            // (a rule is configured 1-to-1 with its initial batch). issued_at/due_date are
            // REQUIRED for direct and OPTIONAL for periodic (first period derived from valid_from).
            'target_type' => ['required', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => ['nullable', 'array'],
            'issued_at' => [Rule::requiredIf(fn (): bool => $isDirect), 'nullable', 'date'],
            'due_date' => [Rule::requiredIf(fn (): bool => $isDirect), 'nullable', 'date', 'after_or_equal:issued_at'],

            'is_active' => ['nullable', 'boolean'],

            // Optional late-fee schedule (recargos moratorios). Each rule escalates from
            // a delinquency month. Feeds the SurchargeRulesField on the frontend.
            'surcharge_rules' => ['nullable', 'array'],
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
        ];

        // Idempotency guard mirrored from the DB partial unique index
        // charge_policy_active_unique (tenant_id, charge_concept_id, periodicity_id)
        // WHERE deleted_at IS NULL AND is_active = true. A new policy is active unless
        // is_active is explicitly false, so only guard when the row would be active —
        // surface a clean 422 instead of a raw 500 on the constraint violation.
        if ($this->boolean('is_active', true)) {
            $rules['periodicity_id'][] = Rule::unique('charge_policy')
                ->where('tenant_id', tenant('id'))
                ->where('charge_concept_id', $this->input('charge_concept_id'))
                ->where('is_active', true)
                ->whereNull('deleted_at');
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'valid_to.after_or_equal' => "La fecha 'Hasta' debe ser posterior o igual a 'Vigente desde'.",
            'valid_from.prohibited_if' => 'El cobro directo no captura ventana de vigencia.',
            'valid_to.prohibited_if' => 'El cobro directo no captura ventana de vigencia.',
            'target_type.in' => 'El alcance del cobro no es válido.',
            'due_date.after_or_equal' => 'La fecha de vencimiento debe ser posterior o igual a la fecha de emisión.',
            'periodicity_id.unique' => 'Ya existe una política activa para este concepto y periodicidad.',
            'periodicity_id.not_in' => 'Esta periodicidad es de pruebas y no está disponible en producción.',
            'surcharge_rules.*.from_month.distinct' => 'No puede haber dos reglas de recargo para el mismo mes de morosidad.',
        ];
    }

    /**
     * Cross-field coherence: RANGE bounds (min ≤ ideal ≤ max). The periodic/automatic
     * contract is now intrinsic — the periodicity itself determines the mode, so a
     * non-automatic periodicity simply IS a direct policy (window prohibited via rules()).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            ChargeConceptRangeRule::validate($this, $validator);
        });
    }

    private function amountModeIs(string ...$modeIds): bool
    {
        return in_array($this->input('amount_mode_id'), $modeIds, true);
    }

    public function authorize(): bool
    {
        return true;
    }
}
