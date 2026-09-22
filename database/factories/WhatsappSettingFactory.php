<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\WhatsappSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsappSettingFactory extends Factory
{
    protected $model = WhatsappSetting::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'provider' => WhatsappSetting::PROVIDER_AYOSPUSH,
            'api_key' => 'ak_' . $this->faker->unique()->lexify('????????????'),
            'api_secret' => 'as_' . $this->faker->lexify('????????????????????????'),
            'test_status' => 'not_tested',
        ];
    }

    /**
     * As left by a successful connection test on a single-WABA account.
     */
    public function connected(): static
    {
        return $this->state(fn () => [
            'scopes' => ['*'],
            'waba_accounts' => [[
                'id' => 7,
                'waba_id' => '102030405060708',
                'name' => 'ANINF',
                'status' => 'active',
                'verification_status' => 'verified',
                'phone_numbers_count' => 1,
                'templates_count' => 0,
            ]],
            'phone_numbers' => [[
                'phone_number_id' => '111222333444555',
                'display_phone_number' => '+241 77 00 00 00',
                'verified_name' => 'ANINF',
                'quality_rating' => 'GREEN',
                'messaging_limit_tier' => 'TIER_1K',
                'status' => 'connected',
                'waba_account_id' => 7,
            ]],
            'default_waba_account_id' => 7,
            'default_phone_number_id' => '111222333444555',
            'meta_business_id' => '998877665544',
            'connection_status' => 'active',
            'test_status' => 'success',
            'last_tested_at' => now(),
        ]);
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }
}
