<?php

namespace Modules\Projects\Http\Requests;

use Orion\Http\Requests\Request;

class ProjectCommentUpdateRequest extends Request
{
    /**
     * Only the body is editable. Author, project, thread and moderation state are
     * immutable through the standard update (hide goes through /hide).
     */
    public function rules(): array
    {
        return [
            'body' => ['sometimes', 'required', 'string', 'max:2000'],
            'community_project_id' => ['prohibited'],
            'parent_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'hidden' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.prohibited' => 'El autor del comentario no se puede cambiar.',
            'hidden.prohibited' => 'La moderación de comentarios se hace con la acción ocultar.',
        ];
    }
}
