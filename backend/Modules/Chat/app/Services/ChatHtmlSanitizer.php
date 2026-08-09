<?php

namespace Modules\Chat\Services;

use Mews\Purifier\Facades\Purifier;

/**
 * Sanitizes the rich-text HTML of a chat message server-side (XSS defense) with a
 * STRICT allowlist via HTMLPurifier (mews/purifier). Only formatting markup
 * survives: b/strong, i/em, u, p, br, ul/ol/li, span with color + font-size, and
 * links with http/https/mailto hrefs. Scripts, event handlers, javascript: URIs,
 * style expressions and every other tag/attribute are stripped.
 */
class ChatHtmlSanitizer
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
     * Return the sanitized HTML (safe to store in body_html and render).
     */
    public function sanitize(string $html): string
    {
        return trim((string) Purifier::clean($html, self::CONFIG));
    }

    /**
     * Derive the plain-text body from sanitized HTML — used for notifications and
     * previews, which NEVER use the HTML.
     */
    public function toPlainText(string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }
}
