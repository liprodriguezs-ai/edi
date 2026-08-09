<?php

namespace Modules\Package\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackagePickupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // max:20 bounds the blast radius of a single handover (N UPDATEs + up to
            // 2N Spatie media copies per package). A guard hands over a few packages at
            // a time; an unbounded array would let a caller issue arbitrarily many writes.
            'package_ids' => ['required', 'array', 'min:1', 'max:20'],
            'package_ids.*' => ['required', 'uuid'],
            // pickup_code is the resident's QR-encoded code. Optional at the form layer —
            // business-rule enforcement (require_pickup_code setting) happens in PackageService.
            'pickup_code' => ['nullable', 'string', 'max:20'],
            'pickup_proxy_name' => ['nullable', 'string', 'max:150'],
            'pickup_proxy_relationship' => ['nullable', 'string', 'max:100'],
            'pickup_evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'pickup_signature' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
