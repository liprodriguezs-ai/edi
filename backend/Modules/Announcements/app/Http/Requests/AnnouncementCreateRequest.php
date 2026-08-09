<?php

namespace Modules\Announcements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Announcements\Services\AnnouncementService;

/**
 * announcement_status_id and author_user_id deliberately have NO rules here:
 * they are excluded from validated() and forced server-side by the controller
 * (status is DRAFT on create; transitions go through publish/archive only).
 */
class AnnouncementCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // Rich text (dual field), mirrors Modules/Chat's body/body_html: the client
            // may send content_html (WYSIWYG) OR plain content, but AT LEAST ONE is
            // required (required_without). When content_html is present it is sanitized
            // server-side and content is DERIVED from it (client content is not trusted);
            // when only content is sent it is kept as plain text and content_html is null.
            // content_html is NOT mass-assignable — see AnnouncementService::applyRichTextContent.
            'content_html' => ['nullable', 'string', 'max:20000', 'required_without:content'],
            'content' => ['nullable', 'string', 'required_without:content_html'],
            // announcement_type / announcement_priority are CENTRAL catalogs (no tenant_id).
            'announcement_type_id' => ['required', 'uuid', Rule::exists('announcement_type', 'id')],
            'announcement_priority_id' => ['required', 'uuid', Rule::exists('announcement_priority', 'id')],
            // §12.3.3 Alcance: strict whitelist — an unknown target_type must 422,
            // never fall back silently to "todo el clúster".
            'target_type' => ['sometimes', 'required', 'string', Rule::in(AnnouncementService::TARGET_TYPES)],
            'target_selector' => ['nullable', 'array'],
            'channel_push' => ['sometimes', 'boolean'],
            'channel_board' => ['sometimes', 'boolean'],
            'channel_email' => ['sometimes', 'boolean'],
            'channel_sms' => ['sometimes', 'boolean'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
