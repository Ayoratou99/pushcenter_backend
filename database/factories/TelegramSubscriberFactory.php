<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\TelegramSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramSubscriberFactory extends Factory
{
    protected $model = TelegramSubscriber::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'chat_id' => $this->faker->unique()->numberBetween(100000000, 999999999),
            'external_ref' => 'citizen-' . $this->faker->unique()->numberBetween(1000, 9999),
            'username' => $this->faker->userName(),
            'first_name' => $this->faker->firstName(),
            'status' => 'active',
            'subscribed_at' => now(),
        ];
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => ['business_id' => $business instanceof Business ? $business->id : $business]);
    }
}
