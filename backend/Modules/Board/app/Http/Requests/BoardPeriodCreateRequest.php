<?php

namespace Modules\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardPeriodCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('board_period', 'name')->where('tenant_id', tenant('id')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'board_period_status_id' => [
                'required',
                'uuid',
                Rule::exists('board_period_status', 'id'),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }
}
