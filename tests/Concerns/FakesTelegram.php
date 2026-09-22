<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * A fake Telegram Bot API: routes keyed by method name (getMe, sendMessage…),
 * answers shaped like the real one ({"ok": true, "result": …} or
 * {"ok": false, "error_code": …, "description": …}).
 */
trait FakesTelegram
{
    protected string $telegramUrl = 'https://telegram.test';

    /**
     * @param  array<string, callable(Request): mixed>  $methods
     */
    protected function fakeTelegram(array $methods = []): void
    {
        config(['services.telegram.api_url' => $this->telegramUrl]);

        $methods = $methods + [
            'getMe' => fn () => $this->telegramOk([
                'id' => 123456789,
                'is_bot' => true,
                'first_name' => 'AninfPush',
                'username' => 'AninfPushBot',
            ]),
            'getWebhookInfo' => fn () => $this->telegramOk(['url' => '', 'pending_update_count' => 0]),
            'getUpdates' => fn () => $this->telegramOk([]),
            'sendMessage' => fn (Request $request) => $this->telegramOk([
                'message_id' => 42,
                'chat' => ['id' => $request['chat_id'], 'type' => 'private'],
                'date' => time(),
                'text' => $request['text'],
            ]),
            // Every size of a photo, the largest last.
            'sendPhoto' => fn (Request $request) => $this->telegramOk([
                'message_id' => 43,
                'chat' => ['id' => (int) $this->telegramParam($request, 'chat_id'), 'type' => 'private'],
                'photo' => [['file_id' => 'photo-small', 'width' => 90], ['file_id' => 'photo-large', 'width' => 1280]],
            ]),
            'sendVideo' => fn (Request $request) => $this->telegramOk([
                'message_id' => 44,
                'chat' => ['id' => (int) $this->telegramParam($request, 'chat_id'), 'type' => 'private'],
                'video' => ['file_id' => 'video-id'],
            ]),
            'sendDocument' => fn (Request $request) => $this->telegramOk([
                'message_id' => 45,
                'chat' => ['id' => (int) $this->telegramParam($request, 'chat_id'), 'type' => 'private'],
                'document' => ['file_id' => 'document-id', 'file_name' => 'guide.pdf'],
            ]),
        ];

        Http::fake(function (Request $request) use ($methods) {
            // Anything else (an application webhook…) is left to other fakes.
            if (! str_starts_with($request->url(), $this->telegramUrl . '/')) {
                return null;
            }

            $method = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return isset($methods[$method])
                ? $methods[$method]($request)
                : Http::response(['ok' => false, 'error_code' => 404, 'description' => 'Not Found'], 404);
        });
    }

    protected function telegramOk(mixed $result): mixed
    {
        return Http::response(['ok' => true, 'result' => $result]);
    }

    protected function telegramError(int $code, string $description, array $parameters = []): mixed
    {
        return Http::response(array_filter([
            'ok' => false,
            'error_code' => $code,
            'description' => $description,
            'parameters' => $parameters ?: null,
        ]), $code);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function telegramStartUpdate(int $updateId, int $chatId, string $text = '/start', array $overrides = []): array
    {
        return array_replace_recursive([
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Awa', 'last_name' => 'Ndong', 'username' => 'awa_n', 'language_code' => 'fr'],
                'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => 'Awa'],
                'date' => time(),
                'text' => $text,
            ],
        ], $overrides);
    }

    /**
     * A parameter of a Bot API call, sent as JSON or as a multipart form field.
     */
    protected function telegramParam(Request $request, string $name): mixed
    {
        $part = $this->telegramPart($request, $name);

        return $part !== null ? $part['contents'] : ($request->data()[$name] ?? null);
    }

    /**
     * A multipart part (an uploaded file: contents, filename), null in a JSON call.
     *
     * @return array<string, mixed>|null
     */
    protected function telegramPart(Request $request, string $name): ?array
    {
        $data = $request->data();

        if (! array_is_list($data)) {
            return null;
        }

        foreach ($data as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                if (is_resource($part['contents'] ?? null)) {
                    $part['contents'] = stream_get_contents($part['contents'], -1, 0);
                }

                return $part;
            }
        }

        return null;
    }

    /**
     * Bot API calls made, by method name.
     *
     * @return Collection<int, Request>
     */
    protected function telegramCalls(string $method): Collection
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => basename((string) parse_url($request->url(), PHP_URL_PATH)) === $method)
            ->values();
    }
}
