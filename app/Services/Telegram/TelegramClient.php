<?php

namespace App\Services\Telegram;

use App\Models\TelegramSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Telegram Bot API client for one bot.
 *
 * The token is part of every URL (https://api.telegram.org/bot<token>/...),
 * and HTTP errors quote the URL: it is scrubbed from every message so it never
 * lands in the database or the logs.
 */
class TelegramClient
{
    public function __construct(private readonly string $token)
    {
    }

    public static function for(TelegramSetting $settings): self
    {
        return new self((string) $settings->bot_token);
    }

    /**
     * @return array<string, mixed>  id, is_bot, first_name, username...
     */
    public function getMe(): array
    {
        return (array) $this->call('getMe');
    }

    /**
     * @return array<string, mixed>  url (empty when none), pending_update_count...
     */
    public function getWebhookInfo(): array
    {
        return (array) $this->call('getWebhookInfo');
    }

    /**
     * Updates after $offset (message and my_chat_member only).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $limit = 100): array
    {
        return array_values(array_filter((array) $this->call('getUpdates', [
            'offset' => $offset,
            'limit' => $limit,
            'timeout' => 0,
            'allowed_updates' => ['message', 'my_chat_member'],
        ]), 'is_array'));
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed>  the sent Message (message_id, chat, date...)
     */
    public function sendMessage(int $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null, bool $disableLinkPreview = false): array
    {
        $this->pace();

        return (array) $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => in_array($parseMode, ['HTML', 'MarkdownV2'], true) ? $parseMode : null,
            'reply_markup' => $replyMarkup,
            'link_preview_options' => $disableLinkPreview ? ['is_disabled' => true] : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * A photo, video or document, with the text as its caption.
     *
     * $media is a link Telegram downloads itself, a file id Telegram already
     * has, or a file to upload: ['contents' => string|resource, 'filename' => string].
     *
     * @param  string|array{contents: mixed, filename: string}  $media
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed>  the sent Message
     */
    public function sendMedia(string $type, int $chatId, string|array $media, ?string $caption = null, ?string $parseMode = null, ?array $replyMarkup = null): array
    {
        [$method, $field] = match ($type) {
            'photo' => ['sendPhoto', 'photo'],
            'video' => ['sendVideo', 'video'],
            'document' => ['sendDocument', 'document'],
        };

        $params = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption !== null && trim($caption) !== '' ? $caption : null,
            'parse_mode' => $caption !== null && trim($caption) !== '' && in_array($parseMode, ['HTML', 'MarkdownV2'], true) ? $parseMode : null,
            'reply_markup' => $replyMarkup,
            // Plays in the chat instead of downloading first.
            'supports_streaming' => $type === 'video' ? true : null,
        ], fn ($value) => $value !== null);

        $this->pace();

        if (is_string($media)) {
            return (array) $this->call($method, [$field => $media] + $params);
        }

        return (array) $this->call($method, $params, [
            'field' => $field,
            'contents' => $media['contents'],
            'filename' => $media['filename'],
        ]);
    }

    /**
     * Telegram's id for the file of a sent message, to send it again without
     * uploading it (ids are per bot).
     *
     * @param  array<string, mixed>  $message
     */
    public static function fileIdOf(array $message, string $type): ?string
    {
        $id = match ($type) {
            // Every size of the photo, the largest last.
            'photo' => is_array($message['photo'] ?? null) ? (end($message['photo'])['file_id'] ?? null) : null,
            'video' => $message['video']['file_id'] ?? $message['animation']['file_id'] ?? $message['document']['file_id'] ?? null,
            default => $message['document']['file_id'] ?? $message['animation']['file_id'] ?? null,
        };

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{field: string, contents: mixed, filename: string}|null  $file  sent as multipart/form-data
     */
    public function call(string $method, array $params = [], ?array $file = null): mixed
    {
        $url = rtrim((string) config('services.telegram.api_url', 'https://api.telegram.org'), '/')
            . '/bot' . $this->token . '/' . $method;

        try {
            $http = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config($file ? 'services.telegram.upload_timeout' : 'services.telegram.timeout', $file ? 45 : 15))
                ->withUserAgent('AninfPush');

            $response = $file
                ? $http->attach($file['field'], $file['contents'], $file['filename'])->post($url, $this->formFields($params))
                : $http->post($url, $params);
        } catch (ConnectionException $e) {
            $neverSent = (bool) preg_match(
                '/cURL error (6|7)\b|Could not resolve host|Failed to connect|Connection refused|Connection timed out/i',
                $e->getMessage()
            );

            throw new TelegramException(
                ($neverSent ? 'Telegram could not be reached: ' : 'No answer from Telegram: ') . $this->scrub($e->getMessage()),
                0,
                neverSent: $neverSent,
            );
        }

        $body = $response->json();

        if (is_array($body) && ($body['ok'] ?? false) === true) {
            return $body['result'] ?? null;
        }

        $status = (int) (is_array($body) ? ($body['error_code'] ?? $response->status()) : $response->status());
        $description = $this->scrub((string) (is_array($body) ? ($body['description'] ?? '') : ''))
            ?: 'HTTP ' . $response->status();
        $retryAfter = is_array($body) && isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : null;

        $prefix = match (true) {
            $status === 401, $status === 404 => 'Telegram rejected the bot token',
            $status === 429 => 'Telegram rate limit reached',
            default => 'Telegram refused the request',
        };

        throw new TelegramException("{$prefix}: {$description}", $status, $description, $retryAfter);
    }

    /**
     * Form fields are strings: objects (reply_markup...) go JSON-encoded.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function formFields(array $params): array
    {
        return array_map(fn ($value) => match (true) {
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        }, $params);
    }

    /**
     * Stay under ~30 messages per second per bot, shared by every worker.
     */
    private function pace(): void
    {
        $perSecond = max(1, (int) config('services.telegram.messages_per_second', 25));
        $key = 'telegram:pace:' . substr(hash('sha256', $this->token), 0, 32);

        for ($waited = 0; $waited < 12 && RateLimiter::tooManyAttempts($key, $perSecond); $waited++) {
            usleep(100_000);
        }

        RateLimiter::hit($key, 1);
    }

    private function scrub(string $text): string
    {
        return $this->token === '' ? $text : str_replace($this->token, '***', $text);
    }
}
