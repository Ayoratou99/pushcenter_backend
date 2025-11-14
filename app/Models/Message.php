<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'message_id',
        'external_id',
        'channel',
        'type',
        'recipient',
        'recipient_name',
        'sender',
        'subject',
        'content',
        'metadata',
        'status',
        'error_message',
        'retry_count',
        'queued_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'campaign_id',
        'campaign_name',
        'cost',
        'currency',
    ];

    protected $casts = [
        'metadata' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function whatsappMessage()
    {
        return $this->hasOne(WhatsAppMessage::class);
    }

    public function smsMessage()
    {
        return $this->hasOne(SmsMessage::class);
    }

    public function emailMessage()
    {
        return $this->hasOne(EmailMessage::class);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}


