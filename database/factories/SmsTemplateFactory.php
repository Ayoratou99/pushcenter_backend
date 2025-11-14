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
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->words(3, true),
            'content' => $this->faker->text(160),
            'variables' => ['name', 'code', 'date'],
            'category' => $this->faker->randomElement(['marketing', 'transactional', 'notification']),
            'status' => $this->faker->randomElement(['draft', 'active', 'archived']),
            'description' => $this->faker->sentence(),
        ];
    }
}

