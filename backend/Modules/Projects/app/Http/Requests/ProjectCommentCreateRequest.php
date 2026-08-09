<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\ProjectComment;
use Orion\Http\Requests\Request;

class ProjectCommentCreateRequest extends Request
{
    /**
     * §11.8.8: any resident with access may comment, threads via parent_id. The
     * author is ALWAYS the authenticated user (user_id is rejected — finding
     * projects-07) and moderation state cannot be self-assigned. The project must
     * allow comments and the parent must belong to the same project
     * (finding projects-09).
     */
    public function rules(): array
    {
        return [
            'community_project_id' => [
                'required',
                'uuid',
                Rule::exists('community_project', 'id')->where('tenant_id', tenant('id')),
                $this->projectAllowsComments(),
            ],
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('project_comment', 'id')->where('tenant_id', tenant('id')),
                $this->parentBelongsToSameProject(),
            ],
            'body' => ['required', 'string', 'max:2000'],
            'user_id' => ['prohibited'],
            'hidden' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.prohibited' => 'El autor del comentario siempre es el usuario autenticado.',
            'hidden.prohibited' => 'La moderación de comentarios se hace con la acción ocultar.',
        ];
    }

    protected function projectAllowsComments(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $project = CommunityProject::find($value);

            if ($project && ! $project->allow_comments) {
                $fail('Este proyecto no permite comentarios.');
            }
        };
    }

    protected function parentBelongsToSameProject(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value) {
                return;
            }

            $parent = ProjectComment::find($value);

            if ($parent && $parent->community_project_id !== $this->input('community_project_id')) {
                $fail('El comentario padre pertenece a otro proyecto.');
            }
        };
    }
}
