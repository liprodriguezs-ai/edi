<?php

namespace App\Services;

use Mews\Purifier\Facades\Purifier;

/**
 * Sanitizes rich-text HTML server-side (XSS defense) with a STRICT allowlist via
 * HTMLPurifier (mews/purifier), and derives the plain-text strip used for search
 * and notification excerpts. Only formatting markup survives: b/strong, i/em, u,
 * p, br, ul/ol/li, span with color + font-size, and links with http/https/mailto
 * hrefs. Scripts, event handlers, javascript: URIs, style expressions and every
 * other tag/attribute are stripped.
 *
 * This is the reusable counterpart of Modules\Chat\Services\ChatHtmlSanitizer —
 * same allowlist — so any module storing a content/content_html dual field can
 * share one sanitizer. Chat keeps its own copy for now (migrating it is optional).
 */
class RichTextSanitizer
{
    /**
     * @var array<string, mixed>
     */
    private const CONFIG = [
        'HTML.Allowed' => 'p,br,b,strong,i,em,u,span[style],ul,ol,li,a[href]',
        'CSS.AllowedProperties' => 'color,font-size',
        'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
        'HTML.TargetBlank' => true,
        'AutoFormat.AutoParagraph' => false,
        'AutoFormat.RemoveEmpty' => true,
    ];

    /**
     * Return the sanitized HTML (safe to store in content_html and render).
     */
    public static function sanitize(string $html): string
    {
        return trim((string) Purifier::clean($html, self::CONFIG));
    }

    /**
     * Derive the plain-text body from HTML — used for search and notification
     * excerpts, which NEVER use the HTML.
     */
    public static function toPlainText(string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }
}
