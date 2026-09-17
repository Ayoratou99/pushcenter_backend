<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Template ' . $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(['email', 'sms', 'whatsapp']),
            'category' => $this->faker->randomElement(['marketing', 'transactional', 'notification']),
            'status' => 'draft',
            'is_active' => true,
            'usage_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active', 'is_active' => true]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
