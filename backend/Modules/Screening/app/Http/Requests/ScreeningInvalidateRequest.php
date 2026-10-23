<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-SCR-011 — reason is mandatory; the `delete.screening` permission is
 * enforced by route middleware, not here.
 */
class ScreeningInvalidateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
