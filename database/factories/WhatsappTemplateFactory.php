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
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->words(3, true),
            'content' => $this->faker->paragraph(),
            'language' => $this->faker->randomElement(['en', 'fr', 'es']),
            'variables' => ['name', 'product', 'date'],
            'category' => $this->faker->randomElement(['marketing', 'transactional', 'notification']),
            'status' => $this->faker->randomElement(['draft', 'pending', 'approved', 'rejected', 'archived']),
            'approval_status' => $this->faker->randomElement(['pending', 'approved', 'rejected']),
            'description' => $this->faker->sentence(),
            'header_type' => $this->faker->randomElement(['none', 'text', 'image', 'video', 'document']),
            'header_content' => $this->faker->sentence(),
            'footer_text' => $this->faker->sentence(3),
            'button_type' => $this->faker->randomElement(['none', 'call_to_action', 'quick_reply']),
            'button_text' => $this->faker->word(),
            'button_url' => $this->faker->url(),
        ];
    }
}

