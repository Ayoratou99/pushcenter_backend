<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class SmtpSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_email',
        'from_name',
        'reply_to_email',
        'reply_to_name',
        'timeout',
        'verify_peer',
        'headers',
        'is_active',
        'is_default',
        'last_tested_at',
        'test_status',
        'test_error',
        'messages_sent',
        'last_used_at',
        'second_limit',
        'hourly_limit',
        'daily_limit',
        'metadata',
    ];

    protected $casts = [
        'headers' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'verify_peer' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'password',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Encrypt password when setting.
     */
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt password when getting.
     */
    public function getPasswordAttribute($value): ?string
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


