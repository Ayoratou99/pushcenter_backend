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
        'message_type',
        'status',
        'error_message',
        'retry_count',
        'sent_at',
        'delivered_at',
        'failed_at',
        'campaign_id',
        'cost',
        'currency',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'cost' => 'decimal:2',
        'retry_count' => 'integer',
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

    /**
     * Channel is stored in `message_type` (email | sms | whatsapp).
     */
    public function scopeByChannel($query, string $channel)
    {
        return $query->where('message_type', $channel);
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

    /**
     * Recipient of the message, whichever channel carried it.
     */
    public function getRecipientAttribute(): ?string
    {
        return $this->emailMessage?->recipient_email
            ?? $this->smsMessage?->recipient_number
            ?? $this->whatsappMessage?->recipient_number;
    }

    /**
     * Template used, whichever channel carried it.
     */
    public function getTemplateIdAttribute(): ?int
    {
        return $this->emailMessage?->template_id
            ?? $this->smsMessage?->template_id
            ?? $this->whatsappMessage?->template_id;
    }
}
