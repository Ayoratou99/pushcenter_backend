<?php

namespace App\Support;

use App\Models\WhatsappTemplate;
use App\Services\AyosPush\AyosPushTemplateService;

/**
 * Values of a WhatsApp template's numbered variables, as AyosPush expects
 * them: an object keyed "1", "2"... Body variables come first, then one value
 * per dynamic URL button (templates imported from AyosPush).
 */
class WhatsappVariables
{
    /**
     * Accepts {"1": "Awa"} as well as ["Awa"]; values become strings.
     *
     * @param  array<int|string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $values = array_is_list($input)
            ? array_combine(range(1, max(1, count($input))), $input ?: [null])
            : $input;

        $normalized = [];
        foreach ($values as $key => $value) {
            if ($value === null || ! is_scalar($value)) {
                continue;
            }
            $normalized[(string) $key] = trim((string) $value);
        }

        ksort($normalized, SORT_NATURAL);

        return $normalized;
    }

    /**
     * Keys the template needs: "1".."n".
     *
     * @return array<int, string>
     */
    public static function expected(WhatsappTemplate $template): array
    {
        $bodyVariables = array_filter(
            AyosPushTemplateService::variablesIn((string) $template->body),
            fn (string $name) => ctype_digit($name)
        );

        $dynamicButtons = collect($template->buttons ?? [])
            ->filter(fn ($button) => is_array($button)
                && strtoupper((string) ($button['type'] ?? '')) === 'URL'
                && str_contains((string) ($button['url'] ?? ''), '{{'))
            ->count();

        $count = count($bodyVariables) + $dynamicButtons;

        return $count === 0 ? [] : array_map('strval', range(1, $count));
    }

    /**
     * What Meta would refuse, keyed by variable number.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function problems(WhatsappTemplate $template, array $values): array
    {
        $problems = [];

        foreach (self::expected($template) as $key) {
            $value = $values[$key] ?? '';

            if ($value === '') {
                $problems[$key] = "Provide a value for {{{$key}}}.";
            } elseif (preg_match('/[\r\n\t]/', $value) || str_contains($value, '     ')) {
                // Meta rejects the whole message otherwise (error 132018).
                $problems[$key] = "{{{$key}}} cannot contain line breaks, tabs or more than 4 consecutive spaces.";
            } elseif (mb_strlen($value) > 1024) {
                $problems[$key] = "{{{$key}}} is limited to 1024 characters.";
            }
        }

        return $problems;
    }

    /**
     * The body as the recipient will read it, for the console.
     *
     * @param  array<string, string>  $values
     */
    public static function render(string $body, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\d+)\s*\}\}/',
            fn (array $match) => $values[$match[1]] ?? $match[0],
            $body
        );
    }

    /**
     * International format with a leading +, or null when it cannot be one.
     */
    public static function normalizeNumber(string $raw): ?string
    {
        $number = preg_replace('/[\s().\-]/', '', trim($raw));

        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        }

        if (! preg_match('/^\+?[1-9]\d{7,14}$/', $number)) {
            return null;
        }

        return '+' . ltrim($number, '+');
    }
}
