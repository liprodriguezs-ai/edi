<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MediaAttachmentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * chat-file-attachments Phase 1 — MediaAttachmentPolicy is the single source
 * of truth for the platform-wide attachment whitelist/caps (design ADR-1).
 */
final class MediaAttachmentPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedExtensionProvider(): array
    {
        return [
            'pdf' => ['pdf'],
            'jpg' => ['jpg'],
            'jpeg' => ['jpeg'],
            'png' => ['png'],
            'webp' => ['webp'],
            'gif' => ['gif'],
            'mp4' => ['mp4'],
            'mp3' => ['mp3'],
            'doc' => ['doc'],
            'docx' => ['docx'],
            'xls' => ['xls'],
            'xlsx' => ['xlsx'],
        ];
    }

    #[DataProvider('allowedExtensionProvider')]
    public function test_whitelist_membership(string $extension): void
    {
        $this->assertTrue(MediaAttachmentPolicy::isAllowedExtension($extension));
        $this->assertArrayHasKey($extension, MediaAttachmentPolicy::MIME_TYPES, 'Every extension must have a mapped mime type (R1 parity)');
    }

    public function test_extension_check_is_case_insensitive(): void
    {
        $this->assertTrue(MediaAttachmentPolicy::isAllowedExtension('PDF'));
        $this->assertTrue(MediaAttachmentPolicy::isAllowedExtension('JPG'));
    }

    public function test_rejects_disallowed_extension(): void
    {
        $this->assertFalse(MediaAttachmentPolicy::isAllowedExtension('zip'));
        $this->assertFalse(MediaAttachmentPolicy::isAllowedExtension('exe'));
    }

    public function test_within_size_limit_accepts_up_to_10mb(): void
    {
        $this->assertTrue(MediaAttachmentPolicy::withinSizeLimit(10 * 1024 * 1024));
    }

    public function test_rejects_11mb_file(): void
    {
        $this->assertFalse(MediaAttachmentPolicy::withinSizeLimit(11 * 1024 * 1024));
    }

    public function test_mimes_rule_produces_the_expected_laravel_rule_string(): void
    {
        $this->assertSame(
            'mimes:pdf,jpg,jpeg,png,webp,gif,mp4,mp3,doc,docx,xls,xlsx',
            MediaAttachmentPolicy::mimesRule(),
        );
    }

    public function test_extensions_and_mime_types_are_1to1(): void
    {
        $this->assertSame(
            MediaAttachmentPolicy::EXTENSIONS,
            array_keys(MediaAttachmentPolicy::MIME_TYPES),
            'MIME_TYPES must be a 1:1 map with EXTENSIONS (design R1 — collection/whitelist parity)'
        );
    }

    public function test_max_files_and_kilobyte_constants(): void
    {
        $this->assertSame(5, MediaAttachmentPolicy::MAX_FILES);
        $this->assertSame(10240, MediaAttachmentPolicy::MAX_FILE_KILOBYTES);
        $this->assertSame(10 * 1024 * 1024, MediaAttachmentPolicy::MAX_FILE_BYTES);
    }
}
