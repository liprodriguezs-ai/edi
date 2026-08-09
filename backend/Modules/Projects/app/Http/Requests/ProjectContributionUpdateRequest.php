<?php

namespace Modules\Projects\Http\Requests;

use Orion\Http\Requests\Request;

class ProjectContributionUpdateRequest extends Request
{
    /**
     * Only the expected amount is editable. Project/unit are immutable and the
     * derived money/status columns are owned by ContributionService
     * (findings projects-09 / sec-input-09).
     */
    public function rules(): array
    {
        return [
            'expected_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
            'community_project_id' => ['prohibited'],
            'unit_id' => ['prohibited'],
            'contributed_amount' => ['prohibited'],
            'declined' => ['prohibited'],
            'project_contribution_status_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'contributed_amount.prohibited' => 'El monto aportado se calcula a partir de los pagos, no se asigna directamente.',
            'declined.prohibited' => 'Usa las acciones declinar/revertir para cambiar la declinación.',
            'project_contribution_status_id.prohibited' => 'El estado de la aportación se calcula a partir de los pagos.',
        ];
    }
}
