<?php

namespace App\Services\Telegram;

use App\Models\TelegramInvitation;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Reads what happened in the bot's chats (getUpdates): who pressed Start,
 * with which invitation, and who blocked or unblocked the bot.
 *
 * Polling rather than a webhook: it needs no public URL and works behind any
 * firewall. `php artisan telegram:poll` runs it every minute.
 */
class TelegramUpdates
{
    /**
     * @return array{updates: int, subscribed: int, blocked: int}
     */
    public function poll(TelegramSetting $settings): array
    {
        $client = TelegramClient::for($settings);
        $counts = ['updates' => 0, 'subscribed' => 0, 'blocked' => 0];

        try {
            $updates = $client->getUpdates($settings->update_offset > 0 ? $settings->update_offset + 1 : 0);
        } catch (TelegramException $e) {
            $settings->forceFill(['poll_error' => $e->getMessage(), 'last_polled_at' => now()])->save();

            throw $e;
        }

        $offset = $settings->update_offset;

        foreach ($updates as $update) {
            $offset = max($offset, (int) ($update['update_id'] ?? 0));
            $counts['updates']++;

            try {
                $outcome = $this->handle($settings, $client, $update);
                if ($outcome) {
                    $counts[$outcome]++;
                }
            } catch (\Throwable $e) {
                // One odd update must not block the others (nor be replayed forever).
                Log::warning('Telegram update not handled', ['business_id' => $settings->business_id, 'error' => $e->getMessage()]);
            }
        }

        $settings->forceFill([
            'update_offset' => $offset,
            'last_polled_at' => now(),
            'poll_error' => null,
        ])->save();

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $update
     * @return 'subscribed'|'blocked'|null
     */
    public function handle(TelegramSetting $settings, TelegramClient $client, array $update): ?string
    {
        if ($message = $update['message'] ?? null) {
            if (Arr::get($message, 'chat.type') !== 'private') {
                return null;
            }

            $text = trim((string) ($message['text'] ?? ''));
            $chatId = (int) Arr::get($message, 'chat.id');

            if ($text === '/start' || str_starts_with($text, '/start ')) {
                $this->subscribe($settings, $client, $chatId, (array) ($message['from'] ?? []), trim(substr($text, 6)));

                return 'subscribed';
            }

            if ($text === '/stop') {
                $this->block($settings, $chatId);
                $this->say($client, $chatId, 'You will no longer receive notifications here. Send /start to subscribe again.');

                return 'blocked';
            }

            return null;
        }

        if ($member = $update['my_chat_member'] ?? null) {
            if (Arr::get($member, 'chat.type') !== 'private') {
                return null;
            }

            $chatId = (int) Arr::get($member, 'chat.id');

            return match (Arr::get($member, 'new_chat_member.status')) {
                'kicked' => tap('blocked', fn () => $this->block($settings, $chatId)),
                'member' => tap(null, fn () => TelegramSubscriber::where('business_id', $settings->business_id)
                    ->where('chat_id', $chatId)
                    ->update(['status' => 'active', 'blocked_at' => null])),
                default => null,
            };
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function subscribe(TelegramSetting $settings, TelegramClient $client, int $chatId, array $from, string $payload): void
    {
        $subscriber = TelegramSubscriber::firstOrNew([
            'business_id' => $settings->business_id,
            'chat_id' => $chatId,
        ]);

        $subscriber->fill([
            'username' => $from['username'] ?? $subscriber->username,
            'first_name' => $from['first_name'] ?? $subscriber->first_name,
            'last_name' => $from['last_name'] ?? $subscriber->last_name,
            'language_code' => $from['language_code'] ?? $subscriber->language_code,
            'status' => 'active',
            'blocked_at' => null,
        ]);
        $subscriber->subscribed_at ??= now();
        $subscriber->save();

        if ($payload !== '') {
            $invitation = TelegramInvitation::where('business_id', $settings->business_id)
                ->where('token', $payload)
                ->first();

            if ($invitation?->isUsable()) {
                if ($invitation->external_ref) {
                    // One reference, one chat: an older chat stops receiving it.
                    TelegramSubscriber::where('business_id', $settings->business_id)
                        ->where('external_ref', $invitation->external_ref)
                        ->whereKeyNot($subscriber->getKey())
                        ->update(['external_ref' => null]);

                    $subscriber->forceFill(['external_ref' => $invitation->external_ref])->save();
                }

                $invitation->forceFill(['used_at' => now(), 'telegram_subscriber_id' => $subscriber->getKey()])->save();
            }
        }

        $this->say(
            $client,
            $chatId,
            $settings->welcome_message
                ?: 'Welcome! You will now receive the notifications of ' . ($settings->business?->name ?? 'this service') . ' here. Send /stop to unsubscribe.'
        );
    }

    private function block(TelegramSetting $settings, int $chatId): void
    {
        TelegramSubscriber::where('business_id', $settings->business_id)
            ->where('chat_id', $chatId)
            ->update(['status' => 'blocked', 'blocked_at' => now()]);
    }

    private function say(TelegramClient $client, int $chatId, string $text): void
    {
        try {
            $client->sendMessage($chatId, $text);
        } catch (TelegramException $e) {
            Log::info('Telegram reply not sent', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }
}
