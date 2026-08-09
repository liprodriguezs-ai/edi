<?php

namespace Modules\Announcements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Announcements\Services\AnnouncementService;

/**
 * announcement_status_id, author_user_id and edited_at deliberately have NO
 * rules here: they are excluded from validated() and managed server-side
 * (status via publish/archive, edited_at stamped by the service on edit).
 */
class AnnouncementUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            // Rich text (dual field): partial-update friendly — a PATCH may touch
            // neither body field, so there is NO required_without here. When content_html
            // is present it is sanitized and content is re-derived from it; when only
            // content is sent it is kept as plain text and content_html is cleared
            // (see AnnouncementService::applyRichTextContent).
            'content_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'content' => ['sometimes', 'nullable', 'string'],
            // announcement_type / announcement_priority are CENTRAL catalogs (no tenant_id).
            'announcement_type_id' => ['sometimes', 'required', 'uuid', Rule::exists('announcement_type', 'id')],
            'announcement_priority_id' => ['sometimes', 'required', 'uuid', Rule::exists('announcement_priority', 'id')],
            // §12.3.3 Alcance: strict whitelist — an unknown target_type must 422,
            // never fall back silently to "todo el clúster".
            'target_type' => ['sometimes', 'required', 'string', Rule::in(AnnouncementService::TARGET_TYPES)],
            'target_selector' => ['sometimes', 'nullable', 'array'],
            'channel_push' => ['sometimes', 'boolean'],
            'channel_board' => ['sometimes', 'boolean'],
            'channel_email' => ['sometimes', 'boolean'],
            'channel_sms' => ['sometimes', 'boolean'],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
