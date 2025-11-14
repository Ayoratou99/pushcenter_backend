<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappPhoneNumber extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'waba_account_id',
        'phone_number_id',
        'phone_number',
        'display_phone_number',
        'verified_name',
        'profile_picture_url',
        'quality_rating',
        'name_status',
        'new_name_status',
        'status',
        'verification_status',
        'verification_code',
        'verification_code_expires_at',
        'verified_at',
        'certificate',
        'messaging_limit',
        'messaging_limit_tier',
        'rate_limit',
        'is_official_business_account',
        'is_pin_enabled',
        'platform_type',
        'throughput',
        'webhook_configuration',
        'search_visibility',
        'account_mode',
        'settings',
        'metadata',
        'facebook_data',
        'last_used_at',
        'last_synced_at',
        'last_status_change_at',
        'webhook_subscribed',
        'webhook_subscribed_at',
        'webhook_error',
    ];

    protected $casts = [
        'verification_code_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'messaging_limit' => 'array',
        'rate_limit' => 'array',
        'is_official_business_account' => 'boolean',
        'is_pin_enabled' => 'boolean',
        'throughput' => 'array',
        'webhook_configuration' => 'array',
        'settings' => 'array',
        'metadata' => 'array',
        'facebook_data' => 'array',
        'last_used_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_status_change_at' => 'datetime',
        'webhook_subscribed' => 'boolean',
        'webhook_subscribed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'VERIFIED');
    }

    /**
     * Check if phone number is active and ready to use.
     */
    public function isReady(): bool
    {
        return $this->status === 'active' && 
               $this->verification_status === 'VERIFIED' &&
               $this->quality_rating !== 'RED';
    }

    /**
     * Get the current messaging limit tier.
     */
    public function getMessagingTier(): string
    {
        return $this->messaging_limit_tier ?? 'TIER_50';
    }
}


