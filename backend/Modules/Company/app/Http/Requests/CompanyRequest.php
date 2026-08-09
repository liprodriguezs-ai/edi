<?php

namespace Modules\Company\Http\Requests;

use App\Rules\MexicanRfc;
use Modules\Company\Models\Company;
use Orion\Http\Requests\Request;

class CompanyRequest extends Request
{
    /**
     * Rules shared by both store and update operations.
     */
    public function commonRules(): array
    {
        return [
            'description' => 'nullable|string|max:500',
            'rfc' => ['nullable', 'string', 'max:13', new MexicanRfc],
            'legal_name' => 'nullable|string|max:255',
            'legal_representative' => 'nullable|string|max:255',
            'employer_registration' => 'nullable|string|max:255',
            'fiscal_address' => 'nullable|string|max:1000',
            'constitutive_deed_number' => 'nullable|string|max:255',
            'constitutive_deed_date' => 'nullable|date',
            'constitutive_notary_number' => 'nullable|string|max:255',
            'constitutive_notary_name' => 'nullable|string|max:255',
            'constitutive_notary_state' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:255',
            'employee_count' => 'nullable|integer|min:1|max:1000000',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            'favicon' => 'nullable|image|mimes:jpg,jpeg,png,svg,ico,webp|max:512',
        ];
    }

    /**
     * Additional rules applied only when storing a new company.
     */
    public function storeRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:company,domain',
        ];
    }

    /**
     * Additional rules applied only when updating an existing company.
     */
    public function updateRules(): array
    {
        $companyId = $this->route('company') instanceof Company
            ? $this->route('company')->id
            : $this->route('company');
        $companyId = $companyId ?? $this->route('id');

        return [
            'name' => 'sometimes|required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:company,domain,'.$companyId,
        ];
    }
}
