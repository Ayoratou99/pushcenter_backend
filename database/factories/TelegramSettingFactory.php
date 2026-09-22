<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\TelegramSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramSettingFactory extends Factory
{
    protected $model = TelegramSetting::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'bot_token' => '123456789:AAH' . $this->faker->regexify('[A-Za-z0-9_-]{32}'),
            'test_status' => 'not_tested',
        ];
    }

    /**
     * As left by a successful getMe.
     */
    public function connected(): static
    {
        return $this->state(fn () => [
            'bot_id' => 123456789,
            'bot_username' => 'AninfPushBot',
            'bot_name' => 'AninfPush',
            'test_status' => 'success',
            'last_tested_at' => now(),
        ]);
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => ['business_id' => $business instanceof Business ? $business->id : $business]);
    }
}
