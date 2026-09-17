<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsappTemplateFactory extends Factory
{
    protected $model = WhatsappTemplate::class;

    public function definition(): array
    {
        $name = 'wa_' . $this->faker->unique()->lexify('??????');

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'display_name' => ucfirst(str_replace('_', ' ', $name)),
            'description' => $this->faker->sentence(),
            'language' => 'fr',
            'category' => $this->faker->randomElement(['MARKETING', 'UTILITY', 'AUTHENTICATION']),
            'header' => ['type' => 'TEXT', 'text' => $this->faker->sentence(3)],
            'body' => 'Bonjour {{1}}, votre commande {{2}} est prête.',
            'footer' => ['text' => $this->faker->sentence(3)],
            'buttons' => [],
            'components' => [],
            'variables' => ['1', '2'],
            'sample_data' => ['1' => 'John', '2' => 'A-123'],
            'status' => 'draft',
            'is_active' => true,
            'allow_variables' => true,
            'max_variables' => 10,
            'cost_per_message' => 20.00,
            'usage_count' => 0,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_at' => now(),
            'is_active' => true,
        ]);
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }
}
