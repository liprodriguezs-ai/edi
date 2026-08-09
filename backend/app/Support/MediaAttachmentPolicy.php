<?php

namespace App\Support;

/**
 * MediaAttachmentPolicy — single source of truth for the platform-wide file
 * attachment whitelist/caps shared by Announcements and Chat (chat-file-attachments).
 *
 * The literals were previously duplicated between
 * `AnnouncementService::attachFile()` and `AnnouncementController::uploadMedia()`.
 * New consumers (Chat) reference this class exclusively so a third copy of the
 * whitelist never drifts. `MIME_TYPES` is a 1:1 map with `EXTENSIONS` — any
 * owner model's `registerMediaCollections()` MUST declare the full
 * `MIME_TYPES` set (see design ADR-1): a narrower collection than this
 * whitelist throws at `addMedia()`/`copy()` for a file the FormRequest already
 * accepted.
 */
final class MediaAttachmentPolicy
{
    /**
     * @var list<string>
     */
    public const EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mp3', 'doc', 'docx', 'xls', 'xlsx',
    ];

    /**
     * Full extension → mime map, 1:1 with EXTENSIONS.
     *
     * @var array<string, string>
     */
    public const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const MAX_FILES = 5;

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_FILE_KILOBYTES = 10240;

    /**
     * Laravel `mimes:` validation rule string built from EXTENSIONS.
     */
    public static function mimesRule(): string
    {
        return 'mimes:'.implode(',', self::EXTENSIONS);
    }

    public static function isAllowedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    public static function withinSizeLimit(int $bytes): bool
    {
        return $bytes <= self::MAX_FILE_BYTES;
    }
}
