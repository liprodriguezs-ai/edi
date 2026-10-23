<?php

namespace Modules\FollowUp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-FUP-006 -- reason is mandatory; the `update.follow_up` permission is
 * enforced by route middleware, not here.
 */
class FollowUpCancelRequest extends FormRequest
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
