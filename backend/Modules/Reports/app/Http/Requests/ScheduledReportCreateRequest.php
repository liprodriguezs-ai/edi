<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduledReportCreateRequest extends FormRequest
{
    /**
     * report_model_key is checked against the runner's own map rather than a free
     * string: an unknown key would only fail at 3am inside the cron, where nobody
     * is watching.
     *
     * The recipient must belong to THIS organization, or a schedule could deliver
     * one organization's data to a stranger.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'report_model_key' => ['required', 'string', Rule::in(['Screening', 'Child', 'ScreeningIndicator'])],
            'params' => ['nullable', 'array'],
            'report_frequency_id' => ['required', 'uuid', Rule::exists('report_frequency', 'id')],
            'recipient_user_id' => [
                'required',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
