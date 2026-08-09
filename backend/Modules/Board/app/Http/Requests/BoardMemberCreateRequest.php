<?php

namespace Modules\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardMemberCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_period_id' => [
                'required',
                'uuid',
                Rule::exists('board_period', 'id')->where('tenant_id', tenant('id')),
            ],
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'board_role_id' => [
                'required',
                'uuid',
                Rule::exists('board_role', 'id'),
            ],
            'unit_id' => ['nullable', 'uuid'],
        ];
    }
}
