<?php

namespace Modules\Chat\Http\Requests;

use App\Support\MediaAttachmentPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatMessageCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'chat_channel_id' => ['required', 'uuid', Rule::exists('chat_channel', 'id')->where('tenant_id', tenant('id'))],
            // Either plain body or rich-text body_html must be present. body_html is
            // sanitized server-side; the plain body is derived from it.
            'body' => ['required_without:body_html', 'nullable', 'string', 'max:4000'],
            'body_html' => ['required_without:body', 'nullable', 'string', 'max:20000'],
            'reply_to_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('chat_message', 'id')->where('tenant_id', tenant('id'))],
            // File attachments (max 5), each ≤10 MB, whitelist shared with
            // Announcements via MediaAttachmentPolicy (chat-file-attachments —
            // generalizes chat from images-only to platform-wide file types);
            // titles parallel by index.
            'attachments' => ['sometimes', 'array', 'max:'.MediaAttachmentPolicy::MAX_FILES],
            'attachments.*' => ['file', MediaAttachmentPolicy::mimesRule(), 'max:'.MediaAttachmentPolicy::MAX_FILE_KILOBYTES],
            'attachment_titles' => ['sometimes', 'array', 'max:'.MediaAttachmentPolicy::MAX_FILES],
            'attachment_titles.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
