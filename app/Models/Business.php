<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'country_code',
        'website',
        'description',
        'address',
        'city',
        'state_province',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'settings',
        'status',
        'verification_status',
        'verified_at',
        'timezone',
        'business_hours',
        'is_24_hours',
        'app_id',
        'webhook_url',
        'webhook_secret',
        'webhook_events',
    ];

    protected $casts = [
        'settings' => 'array',
        'business_hours' => 'array',
        'verified_at' => 'datetime',
        'is_24_hours' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'webhook_events' => 'array',
        'webhook_secret' => 'encrypted',
    ];

    protected $hidden = [
        'deleted_at',
        'app_secret_hash',
        'webhook_secret',
    ];

    /**
     * The generated secret, in clear, for the single response that creates or
     * rotates it. Never persisted and never serialised.
     */
    protected ?string $plainAppSecret = null;

    /**
     * Every application gets its API credentials as soon as it is created.
     */
    protected static function booted(): void
    {
        static::creating(function (self $business) {
            if (empty($business->app_id)) {
                $business->app_id = self::generateAppId();
            }

            if (empty($business->app_secret_hash)) {
                $business->issueAppSecret();
            }
        });
    }

    public static function generateAppId(): string
    {
        return 'app_' . bin2hex(random_bytes(16));
    }

    public static function generateAppSecret(): string
    {
        return 'secret_' . bin2hex(random_bytes(32));
    }

    /**
     * Generate a new secret, keep only its hash, and hold the clear value for
     * the current request so the caller can show it once.
     */
    public function issueAppSecret(): string
    {
        $plain = self::generateAppSecret();

        $this->app_secret_hash = Hash::make($plain);
        $this->app_secret_hint = substr($plain, -4);
        $this->plainAppSecret = $plain;

        return $plain;
    }

    /**
     * The clear secret, available only on the instance that just issued it.
     */
    public function getPlainAppSecret(): ?string
    {
        return $this->plainAppSecret;
    }

    /**
     * Constant-time check of a secret presented by an application.
     */
    public function checkAppSecret(?string $plain): bool
    {
        if (! $plain || ! $this->app_secret_hash) {
            return false;
        }

        return Hash::check($plain, $this->app_secret_hash);
    }

    /**
     * Look an application up by its public identifier.
     */
    public static function findByAppId(?string $appId): ?self
    {
        return $appId ? static::where('app_id', $appId)->first() : null;
    }

    /**
     * Does this application want to be notified of the given event?
     */
    public function wantsWebhook(string $event): bool
    {
        if (! $this->webhook_url) {
            return false;
        }

        // No list configured means every event.
        return empty($this->webhook_events) || in_array($event, $this->webhook_events, true);
    }

    /**
     * Managers explicitly assigned to this application.
     */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Get all messages for the business.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get all templates for the business.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Get all email templates for the business.
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    /**
     * Get all SMS templates for the business.
     */
    public function smsTemplates(): HasMany
    {
        return $this->hasMany(SmsTemplate::class);
    }

    /**
     * Get all WhatsApp templates for the business.
     */
    public function whatsappTemplates(): HasMany
    {
        return $this->hasMany(WhatsappTemplate::class);
    }

    /**
     * Get all WhatsApp phone numbers for the business.
     */
    public function whatsappPhoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsappPhoneNumber::class);
    }

    /**
     * Get all SMS phone numbers for the business.
     */
    public function smsPhoneNumbers(): HasMany
    {
        return $this->hasMany(SmsPhoneNumber::class);
    }

    /**
     * Get all SMTP settings for the business.
     */
    public function smtpSettings(): HasMany
    {
        return $this->hasMany(SmtpSetting::class);
    }

    /**
     * Get all SMS settings for the business.
     */
    public function smsSettings(): HasMany
    {
        return $this->hasMany(SmsSetting::class);
    }

    /**
     * Get Facebook settings for the business.
     */
    public function facebookSettings(): HasMany
    {
        return $this->hasMany(FacebookSetting::class);
    }

    /**
     * Get the default SMTP setting for the business.
     */
    public function defaultSmtpSetting(): HasOne
    {
        return $this->hasOne(SmtpSetting::class)->where('is_default', true);
    }

    /**
     * Get the default SMS setting for the business.
     */
    public function defaultSmsSetting(): HasOne
    {
        return $this->hasOne(SmsSetting::class)->where('is_default', true);
    }

    /**
     * Get active Facebook settings for the business.
     */
    public function activeFacebookSetting(): HasOne
    {
        return $this->hasOne(FacebookSetting::class)->where('status', 'active');
    }

    /**
     * AyosPush credentials used for the WhatsApp channel.
     */
    public function whatsappSetting(): HasOne
    {
        return $this->hasOne(WhatsappSetting::class);
    }

    /**
     * Scope a query to only include active businesses.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include verified businesses.
     */
    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Check if the business is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the business is verified.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Check if the business is currently open based on business hours.
     */
    public function isCurrentlyOpen(): bool
    {
        if ($this->is_24_hours || !$this->business_hours) {
            return true;
        }

        $now = now($this->timezone ?? 'UTC');
        $dayOfWeek = strtolower($now->format('l')); 
        $currentTime = $now->format('H:i');

        $dayHours = $this->business_hours[$dayOfWeek] ?? null;
        
        if (!$dayHours || !($dayHours['enabled'] ?? false)) {
            return false;
        }

        $openTime = $dayHours['open'] ?? '09:00';
        $closeTime = $dayHours['close'] ?? '17:00';

        return $currentTime >= $openTime && $currentTime <= $closeTime;
    }
}

