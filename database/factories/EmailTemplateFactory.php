<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->words(3, true),
            'subject' => $this->faker->sentence(),
            'content' => $this->faker->randomHtml(),
            'sender_name' => $this->faker->name(),
            'sender_email' => $this->faker->safeEmail(),
            'variables' => ['name', 'email', 'date'],
            'category' => $this->faker->randomElement(['marketing', 'transactional', 'notification']),
            'status' => $this->faker->randomElement(['draft', 'active', 'archived']),
            'description' => $this->faker->sentence(),
        ];
    }
}

