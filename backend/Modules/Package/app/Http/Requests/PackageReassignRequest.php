<?php

namespace Modules\Package\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackageReassignRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'uuid'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
