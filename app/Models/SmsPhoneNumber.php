<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsPhoneNumber extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'phone_number',
        'display_phone_number',
        'sender_id',
        'provider',
        'provider_phone_number_id',
        'status',
        'verification_status',
        'verified_at',
        'can_send_sms',
        'can_receive_sms',
        'can_send_mms',
        'daily_limit',
        'monthly_limit',
        'cost_per_sms',
        'currency',
        'settings',
        'metadata',
        'provider_data',
        'messages_sent_today',
        'messages_sent_this_month',
        'last_used_at',
        'last_synced_at',
        'last_status_change_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'can_send_sms' => 'boolean',
        'can_receive_sms' => 'boolean',
        'can_send_mms' => 'boolean',
        'cost_per_sms' => 'decimal:4',
        'settings' => 'array',
        'metadata' => 'array',
        'provider_data' => 'array',
        'last_used_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_status_change_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function smsMessages()
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'VERIFIED');
    }

    public function scopeCanSendSms($query)
    {
        return $query->where('can_send_sms', true);
    }

    /**
     * Check if phone number is ready to send SMS.
     */
    public function isReady(): bool
    {
        return $this->status === 'active' 
            && $this->can_send_sms 
            && $this->verification_status === 'VERIFIED';
    }

    /**
     * Check if daily limit is reached.
     */
    public function hasReachedDailyLimit(): bool
    {
        if (!$this->daily_limit) {
            return false;
        }
        
        return $this->messages_sent_today >= $this->daily_limit;
    }

    /**
     * Check if monthly limit is reached.
     */
    public function hasReachedMonthlyLimit(): bool
    {
        if (!$this->monthly_limit) {
            return false;
        }
        
        return $this->messages_sent_this_month >= $this->monthly_limit;
    }

    /**
     * Increment message counters.
     */
    public function incrementMessageCount(): void
    {
        $this->increment('messages_sent_today');
        $this->increment('messages_sent_this_month');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Reset daily counter (called by scheduled job).
     */
    public function resetDailyCount(): void
    {
        $this->update(['messages_sent_today' => 0]);
    }

    /**
     * Reset monthly counter (called by scheduled job).
     */
    public function resetMonthlyCount(): void
    {
        $this->update(['messages_sent_this_month' => 0]);
    }
}

