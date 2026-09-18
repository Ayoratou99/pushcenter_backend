<?php

namespace App\Services;

/**
 * Substitutes `{{ variable }}` placeholders in template content.
 *
 * Deliberately not Blade: templates are authored by customers, so they must
 * never be able to execute PHP. Only plain replacement happens here.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function render(?string $content, array $variables): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        // Accepts {{name}}, {{ name }} and {{1}} (WhatsApp style).
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $match) use ($variables) {
                $key = $match[1];

                if (! array_key_exists($key, $variables)) {
                    // Leave unknown placeholders untouched rather than emptying
                    // them: a visible {{foo}} is easier to diagnose than a hole.
                    return $match[0];
                }

                $value = $variables[$key];

                return is_scalar($value) ? (string) $value : '';
            },
            $content
        );
    }

    /**
     * Placeholders present in the content.
     *
     * @return array<int, string>
     */
    public function placeholders(?string $content): array
    {
        if (! $content) {
            return [];
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Placeholders the caller did not provide a value for.
     *
     * @param  array<int, string|null>  $contents
     * @param  array<string, mixed>  $variables
     * @return array<int, string>
     */
    public function missingVariables(array $contents, array $variables): array
    {
        $required = [];

        foreach ($contents as $content) {
            $required = array_merge($required, $this->placeholders($content));
        }

        return array_values(array_diff(array_unique($required), array_keys($variables)));
    }
}
