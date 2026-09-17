<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SmsTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmsTemplateFactory extends Factory
{
    protected $model = SmsTemplate::class;

    public function definition(): array
    {
        $message = $this->faker->text(140) . ' {{code}}';

        return [
            'business_id' => Business::factory(),
            'name' => 'SMS ' . $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'message' => $message,
            'message_length' => mb_strlen($message),
            'segments_count' => max(1, (int) ceil(mb_strlen($message) / 160)),
            'variables' => ['name', 'code'],
            'sample_data' => ['name' => 'John', 'code' => '123456'],
            'category' => $this->faker->randomElement(['marketing', 'transactional', 'otp', 'notification']),
            'status' => 'draft',
            'is_active' => true,
            'cost_per_message' => 25.00,
            'usage_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active', 'is_active' => true]);
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }
}
