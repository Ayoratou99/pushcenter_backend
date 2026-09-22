<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A t.me link carrying a random /start payload, tied to the application's own
 * reference for the person it is sent to.
 */
class TelegramInvitation extends Model
{
    protected $fillable = [
        'business_id',
        'token',
        'external_ref',
        'label',
        'expires_at',
        'used_at',
        'telegram_subscriber_id',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(TelegramSubscriber::class, 'telegram_subscriber_id');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
