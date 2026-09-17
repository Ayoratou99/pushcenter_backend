<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Application user, authenticated internally with a JWT access token.
 *
 * Two roles exist:
 *  - admin   : full access, manages users and businesses
 *  - manager : operates either on every business (scope = global) or only on the
 *              businesses attached through the business_user pivot (scope = restricted)
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_RESTRICTED = 'restricted';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'scope',
        'is_active',
        'must_change_password',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'deleted_at',
    ];

    protected $appends = [
        'two_factor_enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Businesses (applications) this manager is explicitly assigned to.
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)->withTimestamps();
    }

    /**
     * Refresh tokens issued to this user.
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    /**
     * An admin, or a manager flagged as "global", reaches every business.
     */
    public function hasUnrestrictedAccess(): bool
    {
        return $this->isAdmin() || $this->scope === self::SCOPE_GLOBAL;
    }

    /**
     * Can this user operate on the given business id?
     */
    public function canAccessBusiness(int|string|null $businessId): bool
    {
        if ($this->hasUnrestrictedAccess()) {
            return true;
        }

        if ($businessId === null) {
            return false;
        }

        return $this->businesses()->whereKey($businessId)->exists();
    }

    /**
     * Business ids the user is allowed to see, or null when unrestricted.
     *
     * @return array<int, int>|null
     */
    public function accessibleBusinessIds(): ?array
    {
        if ($this->hasUnrestrictedAccess()) {
            return null;
        }

        return $this->businesses()->pluck('businesses.id')->all();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function getTwoFactorEnabledAttribute(): bool
    {
        return $this->hasTwoFactorEnabled();
    }

    /**
     * Generate a fresh set of single-use recovery codes.
     *
     * @return array<int, string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5)) . '-' . Str::upper(Str::random(5)))
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }
}
