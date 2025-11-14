<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class SmsSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'provider',
        'api_key',
        'api_secret',
        'account_sid',
        'api_url',
        'sender_id',
        'sender_phone',
        'credentials',
        'is_active',
        'is_default',
        'last_tested_at',
        'test_status',
        'test_error',
        'messages_sent',
        'total_cost',
        'last_used_at',
        'hourly_limit',
        'daily_limit',
        'balance',
        'balance_updated_at',
        'cost_per_sms',
        'currency',
        'webhook_url',
        'webhook_secret',
        'metadata',
    ];

    protected $casts = [
        'credentials' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_used_at' => 'datetime',
        'balance_updated_at' => 'datetime',
        'total_cost' => 'decimal:2',
        'balance' => 'decimal:2',
        'cost_per_sms' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
        'credentials',
        'webhook_secret',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Encrypt api_key when setting.
     */
    public function setApiKeyAttribute($value): void
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt api_key when getting.
     */
    public function getApiKeyAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt api_secret when setting.
     */
    public function setApiSecretAttribute($value): void
    {
        $this->attributes['api_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt api_secret when getting.
     */
    public function getApiSecretAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt webhook_secret when setting.
     */
    public function setWebhookSecretAttribute($value): void
    {
        $this->attributes['webhook_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt webhook_secret when getting.
     */
    public function getWebhookSecretAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Mark this setting as default and unset others.
     */
    public function setAsDefault(): void
    {
        self::where('business_id', $this->business_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);
        
        $this->update(['is_default' => true]);
    }
}


