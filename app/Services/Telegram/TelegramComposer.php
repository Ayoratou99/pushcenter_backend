<?php

namespace App\Services\Telegram;

use App\Models\TelegramTemplate;
use App\Services\TemplateRenderer;

/**
 * Turns a Telegram template and its variables into a sendMessage payload, and
 * checks what Telegram would refuse.
 *
 * Variables use {{ name }}, like email templates. Their values are escaped for
 * the template's format (HTML entities), so a value can never break the
 * markup nor inject a link.
 */
class TelegramComposer
{
    public const MAX_LENGTH = 4096;

    /** The text sent with a photo, video or document is a caption. */
    public const MAX_CAPTION_LENGTH = 1024;

    public const MAX_BUTTONS = 10;

    /**
     * Tags Telegram's HTML accepts.
     */
    public const HTML_TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'span', 'tg-spoiler', 'a', 'code', 'pre', 'blockquote', 'tg-emoji'];

    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    /**
     * Placeholders of the body and of the buttons.
     *
     * @return array<int, string>
     */
    public function placeholders(TelegramTemplate|array $template): array
    {
        [$body, $buttons] = $this->parts($template);

        $names = $this->renderer->placeholders($body);
        foreach ($buttons as $button) {
            $names = array_merge($names, $this->renderer->placeholders((string) ($button['text'] ?? '')), $this->renderer->placeholders((string) ($button['url'] ?? '')));
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<int, string>
     */
    public function missing(TelegramTemplate $template, array $variables): array
    {
        return array_values(array_diff($this->placeholders($template), array_keys(array_filter(
            $variables,
            fn ($value) => is_scalar($value) && trim((string) $value) !== ''
        ))));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{text: string, parse_mode: string|null, reply_markup: array<string, mixed>|null, disable_link_preview: bool}
     */
    public function compose(TelegramTemplate $template, array $variables): array
    {
        $parseMode = $template->parse_mode === 'plain' ? null : $template->parse_mode;

        $escaped = array_map(fn ($value) => is_scalar($value) ? $this->escape((string) $value, $parseMode) : '', $variables);
        $text = $this->renderer->render((string) $template->body, $escaped);

        $rows = [];
        foreach ($template->buttons ?? [] as $button) {
            $url = $this->renderer->render((string) ($button['url'] ?? ''), array_map(
                fn ($value) => is_scalar($value) ? rawurlencode((string) $value) : '',
                $variables
            ));
            $label = $this->renderer->render((string) ($button['text'] ?? ''), array_map(
                fn ($value) => is_scalar($value) ? (string) $value : '',
                $variables
            ));

            if ($url !== '' && $label !== '') {
                // One button per row, as they appear in the editor.
                $rows[] = [['text' => mb_substr($label, 0, 64), 'url' => $url]];
            }
        }

        return [
            'text' => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => $rows ? ['inline_keyboard' => $rows] : null,
            'disable_link_preview' => (bool) $template->disable_web_page_preview,
            'media_type' => $template->media_type,
            'media_source' => $template->media_type ? $template->media_source : null,
        ];
    }

    /**
     * Longest text Telegram takes: a caption when a file goes with it.
     */
    public static function maxLength(?string $mediaType): int
    {
        return $mediaType ? self::MAX_CAPTION_LENGTH : self::MAX_LENGTH;
    }

    /**
     * Length Telegram counts: the text without its HTML markup.
     */
    public function visibleLength(string $text, ?string $parseMode): int
    {
        if ($parseMode === 'HTML') {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return mb_strlen($text);
    }

    /**
     * What Telegram would refuse in a template being saved, keyed by field.
     *
     * @param  array<string, mixed>  $input  body, parse_mode, buttons, media_type
     * @return array<string, array<int, string>>
     */
    public function problems(array $input): array
    {
        $errors = [];
        [$body, $buttons] = $this->parts($input);
        $parseMode = ($input['parse_mode'] ?? 'HTML') === 'plain' ? null : ($input['parse_mode'] ?? 'HTML');
        $mediaType = $input['media_type'] ?? null;

        // A photo or a document may go without text; a message may not.
        if (! $mediaType && trim($body) === '') {
            $errors['body'][] = 'Write the message, or attach a photo, video or document.';
        }

        if ($parseMode === 'HTML') {
            preg_match_all('/<\/?\s*([a-zA-Z0-9-]+)[^>]*>/', $body, $matches);
            $unknown = array_values(array_unique(array_diff(array_map('strtolower', $matches[1] ?? []), self::HTML_TAGS)));

            if ($unknown) {
                $errors['body'][] = 'Telegram does not accept these tags: <' . implode('>, <', $unknown) . '>. Allowed: b, i, u, s, a, code, pre, blockquote, tg-spoiler.';
            }

            // A lone < or & that is not an entity breaks Telegram's parser.
            $withoutTags = preg_replace('/<\/?\s*[a-zA-Z0-9-]+[^>]*>/', '', $body);
            if (preg_match('/<|&(?![a-zA-Z]+;|#\d+;|#x[0-9a-fA-F]+;)/', (string) $withoutTags)) {
                $errors['body'][] = 'Write "<" as &lt; and "&" as &amp; in HTML templates.';
            }

            preg_match_all('/<(\/?)\s*([a-zA-Z0-9-]+)([^>]*)>/', $body, $tags, PREG_SET_ORDER);

            foreach ($tags as [, $closing, $name, $attributes]) {
                $name = strtolower($name);

                if ($closing === '' && $name === 'span' && ! preg_match('/class\s*=\s*["\']?tg-spoiler/i', $attributes)) {
                    $errors['body'][] = 'Telegram only accepts <span> as a spoiler: <span class="tg-spoiler">.';
                    break;
                }

                if ($closing === '' && $name === 'a' && ! preg_match('/href\s*=/i', $attributes)) {
                    $errors['body'][] = 'A link needs its address: <a href="https://…">text</a>.';
                    break;
                }
            }

            // Telegram refuses unclosed or crossed tags.
            $open = [];
            foreach ($tags as [, $closing, $name]) {
                $name = strtolower($name);

                if ($closing === '') {
                    $open[] = $name;
                } elseif (array_pop($open) !== $name) {
                    $errors['body'][] = "</{$name}> closes a tag that is not the last one opened.";
                    $open = [];
                    break;
                }
            }

            if ($open) {
                $errors['body'][] = 'Close <' . implode('>, <', array_unique($open)) . '>.';
            }
        }

        if ($this->visibleLength($body, $parseMode) > self::maxLength($mediaType)) {
            $errors['body'][] = $mediaType
                ? 'With a photo, video or document the text is a caption: 1024 characters at most.'
                : 'Telegram messages are limited to 4096 characters.';
        }

        if (count($buttons) > self::MAX_BUTTONS) {
            $errors['buttons'][] = 'At most ' . self::MAX_BUTTONS . ' buttons.';
        }

        foreach ($buttons as $index => $button) {
            $label = 'Button ' . ($index + 1);
            $text = trim((string) ($button['text'] ?? ''));
            $url = trim((string) ($button['url'] ?? ''));

            if ($text === '' || mb_strlen($text) > 64) {
                $errors['buttons'][] = "{$label}: give a text of 1 to 64 characters.";
            }

            // A placeholder is filled when sending.
            $testable = preg_replace('/\{\{\s*[a-zA-Z0-9_.]+\s*\}\}/', 'x', $url);
            if (! preg_match('#^(https?|tg)://\S+$#i', (string) $testable)) {
                $errors['buttons'][] = "{$label}: the link must start with https://, http:// or tg://.";
            }
        }

        return $errors;
    }

    private function escape(string $value, ?string $parseMode): string
    {
        return match ($parseMode) {
            'HTML' => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'MarkdownV2' => preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $value),
            default => $value,
        };
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function parts(TelegramTemplate|array $template): array
    {
        $body = (string) ($template instanceof TelegramTemplate ? $template->body : ($template['body'] ?? ''));
        $buttons = $template instanceof TelegramTemplate ? ($template->buttons ?? []) : ($template['buttons'] ?? []);

        return [$body, array_values(array_filter((array) $buttons, 'is_array'))];
    }
}
