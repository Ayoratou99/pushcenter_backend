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
            'name' => 'Email ' . $this->faker->unique()->words(3, true),
            'subject' => $this->faker->sentence(),
            'description' => $this->faker->sentence(),
            'design' => ['counters' => [], 'body' => ['rows' => []]],
            'html' => '<html><body><p>Hello {{name}}</p></body></html>',
            'plain_text' => 'Hello {{name}}',
            'variables' => ['name', 'email', 'date'],
            'sample_data' => ['name' => 'John', 'email' => 'john@example.com'],
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

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived', 'is_active' => false]);
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }
}
