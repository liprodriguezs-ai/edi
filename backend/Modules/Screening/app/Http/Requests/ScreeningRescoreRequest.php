<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-SCR-010 — reason is mandatory for every rescore (authoritative or
 * simulation); the `rescore.screening` permission is enforced by route
 * middleware, not here.
 */
class ScreeningRescoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'authoritative' => ['sometimes', 'boolean'],
            'instrument_version_key' => ['sometimes', 'nullable', 'string'],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
