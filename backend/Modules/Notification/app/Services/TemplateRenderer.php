<?php

namespace Modules\Notification\Services;

use Modules\Setting\Models\Setting;

/**
 * TemplateRenderer — renders notification templates with
 * {variable} substitution from a tenant-scoped Setting row.
 *
 * Templates live in the Setting table under the slug
 * `notification_template.{event}` with a JSON value of the shape:
 *   { "title": "...", "body": "Hola {nombre_residente} ...", "is_active": true }
 *
 * A template whose `is_active` is false is considered SUPPRESSED (§16.4 r4): the
 * dispatcher must NOT send that notification. Callers detect this via the
 * `is_active` flag returned by render() (null = no template found).
 */
class TemplateRenderer
{
    /**
     * Render a template by slug with the given context.
     *
     * @param  array<string, scalar|null>  $context  variable name => value (without braces)
     * @return array{title: string, body: string, is_active: bool}|null
     *                                                                  null when no template row exists for the slug
     */
    public function render(string $slug, array $context = []): ?array
    {
        $template = $this->resolveTemplate($slug);

        if ($template === null) {
            return null;
        }

        return [
            'title' => $this->substitute((string) ($template['title'] ?? ''), $context),
            'body' => $this->substitute((string) ($template['body'] ?? ''), $context),
            'is_active' => (bool) ($template['is_active'] ?? true),
        ];
    }

    /**
     * Replace every {key} token in the string with its context value. Unknown
     * tokens are left untouched so a missing variable never corrupts the message.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function substitute(string $text, array $context): string
    {
        if ($text === '' || $context === []) {
            return $text;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = (string) ($value ?? '');
        }

        return strtr($text, $replacements);
    }

    /**
     * Load and decode the template JSON value for a slug (tenant-scoped Setting).
     *
     * @return array<string, mixed>|null
     */
    private function resolveTemplate(string $slug): ?array
    {
        $value = Setting::getValue($slug);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
