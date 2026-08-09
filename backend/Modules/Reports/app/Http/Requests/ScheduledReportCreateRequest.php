<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Reports\Models\ScheduledReport;
use Modules\Reports\Services\ReportRegistry;

/**
 * reports-10 — scheduled_report rows were created without ANY validation: an
 * unknown report_key fails silently at job runtime and a bad frequency leaves
 * next_run_at null (the schedule never runs). Wired automatically by the core
 * DelegatingModuleRequest convention on Orion store.
 */
class ScheduledReportCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_key' => [
                'required',
                'string',
                Rule::in(ReportRegistry::allowedKeys()),
            ],
            'frequency' => [
                'required',
                'string',
                Rule::in([
                    ScheduledReport::FREQUENCY_DAILY,
                    ScheduledReport::FREQUENCY_WEEKLY,
                    ScheduledReport::FREQUENCY_MONTHLY,
                ]),
            ],
            'format' => [
                'sometimes',
                'string',
                Rule::in(['pdf', 'excel', 'csv']),
            ],
            'config' => ['sometimes', 'nullable', 'array'],
            'recipients' => ['sometimes', 'nullable', 'array'],
            'recipients.*' => ['string', self::recipientRule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Each recipient must be a valid email OR the UUID of a user belonging to
     * the current tenant (user_tenant membership — same check the sibling
     * Board requests run via Rule::exists, made conditional here).
     */
    public static function recipientRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                $fail('Cada destinatario debe ser un correo válido o el UUID de un usuario del condominio.');

                return;
            }

            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return;
            }

            $isTenantUser = Str::isUuid($value)
                && DB::table('user_tenant')
                    ->where('tenant_id', tenant('id'))
                    ->where('user_id', $value)
                    ->exists();

            if (! $isTenantUser) {
                $fail('Cada destinatario debe ser un correo válido o el UUID de un usuario del condominio.');
            }
        };
    }
}
