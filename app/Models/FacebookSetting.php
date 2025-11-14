<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class FacebookSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'meta_business_id',
        'app_id',
        'waba_id',
        'access_token',
        'token_type',
        'token_expires_at',
        'app_secret',
        'webhook_verify_token',
        'status',
        'connected_at',
        'last_verified_at',
        'webhook_url',
        'webhook_fields',
        'webhook_subscribed',
        'webhook_subscribed_at',
        'granted_permissions',
        'required_permissions',
        'api_call_count',
        'usage_percentage',
        'is_throttled',
        'throttle_until',
        'rate_limit_reset_at',
        'last_error',
        'last_error_at',
        'error_count',
        'user_info',
        'metadata',
        'settings',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'webhook_fields' => 'array',
        'webhook_subscribed' => 'boolean',
        'webhook_subscribed_at' => 'datetime',
        'granted_permissions' => 'array',
        'required_permissions' => 'array',
        'usage_percentage' => 'decimal:2',
        'is_throttled' => 'boolean',
        'throttle_until' => 'datetime',
        'rate_limit_reset_at' => 'datetime',
        'last_error_at' => 'datetime',
        'user_info' => 'array',
        'metadata' => 'array',
        'settings' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'app_secret',
        'webhook_verify_token',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Encrypt access_token when setting.
     */
    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt access_token when getting.
     */
    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt app_secret when setting.
     */
    public function setAppSecretAttribute($value): void
    {
        $this->attributes['app_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt app_secret when getting.
     */
    public function getAppSecretAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt webhook_verify_token when setting.
     */
    public function setWebhookVerifyTokenAttribute($value): void
    {
        $this->attributes['webhook_verify_token'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt webhook_verify_token when getting.
     */
    public function getWebhookVerifyTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeThrottled($query)
    {
        return $query->where('is_throttled', true);
    }

    /**
     * Check if credentials are active and valid.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && 
               (!$this->token_expires_at || $this->token_expires_at->isFuture());
    }

    /**
     * Check if token needs refresh (expires in 7 days or less).
     */
    public function needsTokenRefresh(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }
        
        return $this->token_expires_at->diffInDays(now()) <= 7;
    }

    /**
     * Record an API usage event.
     */
    public function recordApiUsage(int $callCount = 1): void
    {
        $this->increment('api_call_count', $callCount);
    }

    /**
     * Record an error.
     */
    public function recordError(string $error): void
    {
        $this->increment('error_count');
        $this->update([
            'last_error' => $error,
            'last_error_at' => now(),
        ]);
    }

    /**
     * Clear error count (after successful operation).
     */
    public function clearErrors(): void
    {
        $this->update([
            'error_count' => 0,
            'last_error' => null,
            'last_error_at' => null,
        ]);
    }
}

