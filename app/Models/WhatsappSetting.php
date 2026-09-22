<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AyosPush credentials of one application.
 */
class WhatsappSetting extends Model
{
    use HasFactory;

    public const PROVIDER_AYOSPUSH = 'ayospush';

    /**
     * AyosPush scopes needed to configure the channel and manage templates.
     * A key carrying '*' (or the historic business key) has all of them.
     */
    public const REQUIRED_SCOPES = ['config.read', 'templates.read', 'templates.write'];

    /**
     * Not used yet, but sending templates through AyosPush will need them.
     */
    public const SENDING_SCOPES = ['templates.send', 'messages.read'];

    protected $fillable = [
        'business_id',
        'provider',
        'api_key',
        'api_secret',
        'default_waba_account_id',
        'default_phone_number_id',
        'scopes',
        'waba_accounts',
        'phone_numbers',
        'meta_business_id',
        'connection_status',
        'test_status',
        'test_error',
        'last_tested_at',
        'templates_synced_at',
    ];

    protected $hidden = [
        'api_secret',
    ];

    protected $casts = [
        'api_secret' => 'encrypted',
        'default_waba_account_id' => 'integer',
        'scopes' => 'array',
        'waba_accounts' => 'array',
        'phone_numbers' => 'array',
        'last_tested_at' => 'datetime',
        'templates_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (WhatsappSetting $setting) {
            if ($setting->isDirty('api_secret')) {
                $secret = (string) $setting->api_secret;
                $setting->api_secret_hint = $secret === '' ? null : substr($secret, -4);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Scopes among $wanted (REQUIRED_SCOPES by default) the key was not
     * granted, as far as the last connection test knows. Unknown scopes mean
     * nothing is reported missing.
     *
     * @param  array<int, string>|null  $wanted
     * @return array<int, string>
     */
    public function missingScopes(?array $wanted = null): array
    {
        $granted = $this->scopes;

        if (! is_array($granted) || in_array('*', $granted, true)) {
            return [];
        }

        return array_values(array_diff($wanted ?? self::REQUIRED_SCOPES, $granted));
    }

    /**
     * The WABA templates are created on: the chosen one, or the only one.
     */
    public function resolveWabaAccountId(): ?int
    {
        if ($this->default_waba_account_id) {
            return (int) $this->default_waba_account_id;
        }

        $accounts = $this->waba_accounts ?? [];

        return count($accounts) === 1 && isset($accounts[0]['id']) ? (int) $accounts[0]['id'] : null;
    }

    /**
     * Shape returned to the management console. The secret never leaves the
     * server; only its last four characters are shown.
     *
     * @return array<string, mixed>
     */
    public function toConsoleArray(): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'provider' => $this->provider,
            'api_url' => rtrim((string) config('services.ayospush.base_url'), '/'),
            'api_key' => $this->api_key,
            'api_secret_hint' => $this->api_secret_hint,
            'has_secret' => $this->api_secret_hint !== null,
            'default_waba_account_id' => $this->default_waba_account_id,
            'default_phone_number_id' => $this->default_phone_number_id,
            'scopes' => $this->scopes,
            'missing_scopes' => $this->missingScopes(),
            'missing_sending_scopes' => $this->missingScopes(self::SENDING_SCOPES),
            'waba_accounts' => $this->waba_accounts ?? [],
            'phone_numbers' => $this->phone_numbers ?? [],
            'meta_business_id' => $this->meta_business_id,
            'connection_status' => $this->connection_status,
            'test_status' => $this->test_status,
            'test_error' => $this->test_error,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'templates_synced_at' => $this->templates_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
