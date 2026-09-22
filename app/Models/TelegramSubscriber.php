<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who pressed "Start" in the application's bot: the only people the
 * bot is allowed to write to.
 */
class TelegramSubscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'chat_id',
        'external_ref',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'status',
        'subscribed_at',
        'blocked_at',
        'last_message_at',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'subscribed_at' => 'datetime',
        'blocked_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function displayName(): string
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name !== '' ? $name . ($this->username ? " (@{$this->username})" : '') : ($this->username ? "@{$this->username}" : (string) $this->chat_id);
    }
}
