<?php

namespace Modules\Projects\Http\Requests;

class CommunityProjectUpdateRequest extends CommunityProjectCreateRequest
{
    /**
     * Structural/money fields frozen post-create by product decision (2026-07):
     * once a project exists only its presentation is editable, so every field that
     * defines what the project IS or how much it costs is rejected outright (422).
     * Kept as a list so rules() and messages() stay in sync.
     *
     * @var list<string>
     */
    private const FROZEN_FIELDS = [
        'community_project_category_id',
        'participation_type_id',
        'amount_mode_id',
        'fixed_amount',
        'min_amount',
        'ideal_amount',
        'max_amount',
        'goal_amount',
        'target_type',
        'target_selector',
        'start_date',
        'due_date',
        'responsible_user_id',
        'allow_decline',
        'allow_comments',
    ];

    /**
     * Restricted update contract: after creation ONLY the title, rich description
     * and the two visibility toggles are editable (documents are handled by the
     * /media endpoints). Everything structural/money is frozen (self::FROZEN_FIELDS)
     * and the status stays PATCH-proof — the FSM (draft → active →
     * fully_funded/executed/cancelled) only moves through /activate, /close and
     * /cancel (finding projects-10).
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'description_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'visible_to_residents' => ['sometimes', 'nullable', 'boolean'],
            'payments_visible_to_residents' => ['sometimes', 'nullable', 'boolean'],
            'community_project_status_id' => ['prohibited'],
        ];

        foreach (self::FROZEN_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'community_project_status_id.prohibited' => 'El estado del proyecto solo se cambia con las acciones activar, cerrar o cancelar.',
        ];

        foreach (self::FROZEN_FIELDS as $field) {
            $messages["{$field}.prohibited"] = 'Este campo no es editable después de crear el proyecto.';
        }

        return $messages;
    }
}
