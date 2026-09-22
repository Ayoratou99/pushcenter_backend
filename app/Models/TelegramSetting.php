<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Telegram bot of one application (token from @BotFather).
 */
class TelegramSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'bot_token',
        'bot_id',
        'bot_username',
        'bot_name',
        'welcome_message',
        'update_offset',
        'test_status',
        'test_error',
        'last_tested_at',
        'last_polled_at',
        'poll_error',
    ];

    protected $hidden = [
        'bot_token',
    ];

    protected $casts = [
        'bot_token' => 'encrypted',
        'bot_id' => 'integer',
        'update_offset' => 'integer',
        'last_tested_at' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (TelegramSetting $setting) {
            if ($setting->isDirty('bot_token')) {
                $token = (string) $setting->bot_token;
                $setting->bot_token_hint = $token === '' ? null : substr($token, -4);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * t.me link that opens the bot; with a payload, /start carries it.
     */
    public function botLink(?string $payload = null): ?string
    {
        if (! $this->bot_username) {
            return null;
        }

        return 'https://t.me/' . $this->bot_username . ($payload !== null ? '?start=' . $payload : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toConsoleArray(): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'bot_token_hint' => $this->bot_token_hint,
            'has_token' => $this->bot_token_hint !== null,
            'bot_id' => $this->bot_id,
            'bot_username' => $this->bot_username,
            'bot_name' => $this->bot_name,
            'bot_link' => $this->botLink(),
            'welcome_message' => $this->welcome_message,
            'test_status' => $this->test_status,
            'test_error' => $this->test_error,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'poll_error' => $this->poll_error,
            'subscribers' => [
                'active' => TelegramSubscriber::where('business_id', $this->business_id)->where('status', 'active')->count(),
                'blocked' => TelegramSubscriber::where('business_id', $this->business_id)->where('status', 'blocked')->count(),
            ],
        ];
    }
}
