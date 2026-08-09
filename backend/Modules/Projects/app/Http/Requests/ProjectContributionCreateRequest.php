<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Validation\Rule;
use Orion\Http\Requests\Request;

class ProjectContributionCreateRequest extends Request
{
    /**
     * Contributions are normally created by ProjectActivationService on activation;
     * the Orion store only allows declaring the placeholder (project + unit +
     * expected). The MONEY columns and the status are derived state owned by
     * ContributionService — mass-assigning them is rejected with 422
     * (findings projects-09 / sec-input-09).
     */
    public function rules(): array
    {
        return [
            'community_project_id' => ['required', 'uuid', Rule::exists('community_project', 'id')->where('tenant_id', tenant('id'))],
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'expected_amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'contributed_amount' => ['prohibited'],
            'declined' => ['prohibited'],
            'project_contribution_status_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'contributed_amount.prohibited' => 'El monto aportado se calcula a partir de los pagos, no se asigna directamente.',
            'declined.prohibited' => 'Usa la acción declinar para registrar una declinación.',
            'project_contribution_status_id.prohibited' => 'El estado de la aportación se calcula a partir de los pagos.',
        ];
    }
}
