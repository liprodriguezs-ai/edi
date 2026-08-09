<?php

namespace Modules\Providers\Http\Requests;

use App\Rules\MexicanRfc;
use App\Rules\PhoneNumber;
use Illuminate\Validation\Rule;
use Orion\Http\Requests\Request;

class ProviderUpdateRequest extends Request
{
    public function rules(): array
    {
        return [
            'provider_type_id' => ['sometimes', 'required', 'uuid', 'exists:provider_type,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:13', new MexicanRfc],
            'provider_service_type_id' => ['sometimes', 'required', 'uuid', 'exists:provider_service_type,id'],
            'user_id' => [
                'nullable', 'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:20', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_recurring' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'string', 'in:monthly,bimonthly,eventual'],
            'estimated_amount' => ['nullable', 'integer', 'min:0'],
            'usual_payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'clabe' => ['nullable', 'string', 'max:18'],
            'bank' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
