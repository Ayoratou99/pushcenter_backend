<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramMessage extends Model
{
    protected $fillable = [
        'message_id',
        'telegram_template_id',
        'telegram_subscriber_id',
        'chat_id',
        'recipient_label',
        'external_ref',
        'text',
        'parse_mode',
        'reply_markup',
        'media_type',
        'media_source',
        'media_url',
        'disable_link_preview',
        'template_variables',
        'provider_message_id',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'reply_markup' => 'array',
        'disable_link_preview' => 'boolean',
        'template_variables' => 'array',
        'provider_message_id' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TelegramTemplate::class, 'telegram_template_id')->withTrashed();
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(TelegramSubscriber::class, 'telegram_subscriber_id');
    }
}
