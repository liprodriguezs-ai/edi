<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Reports\Models\ScheduledReport;
use Modules\Reports\Services\ReportRegistry;

/**
 * reports-10 — Update counterpart of ScheduledReportCreateRequest (same rules,
 * `sometimes` instead of `required`, per the module FormRequest convention).
 */
class ScheduledReportUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_key' => [
                'sometimes',
                'string',
                Rule::in(ReportRegistry::allowedKeys()),
            ],
            'frequency' => [
                'sometimes',
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
            'recipients.*' => ['string', ScheduledReportCreateRequest::recipientRule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
