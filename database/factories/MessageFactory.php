<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'message_id' => 'msg_' . Str::random(16),
            'external_id' => 'ext_' . Str::random(16),
            'message_type' => $this->faker->randomElement(['email', 'sms', 'whatsapp']),
            'status' => $this->faker->randomElement(['pending', 'queued', 'sending', 'sent', 'delivered', 'read', 'failed', 'cancelled']),
            'error_message' => null,
            'retry_count' => 0,
            'sent_at' => $this->faker->optional()->dateTime(),
            'delivered_at' => $this->faker->optional()->dateTime(),
            'failed_at' => null,
            'campaign_id' => $this->faker->optional()->uuid(),
            'cost' => $this->faker->randomFloat(2, 10, 500),
            'currency' => 'XAF',
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => $this->faker->sentence(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'sent_at' => now()->subHours(2),
            'delivered_at' => now(),
        ]);
    }
}

