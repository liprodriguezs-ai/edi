<?php

namespace Modules\Nutrition\Http\Requests;

class NutritionalAssessmentCreateRequest extends NutritionalAssessmentClassifyRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
