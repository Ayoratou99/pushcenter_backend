<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\TelegramTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramTemplateFactory extends Factory
{
    protected $model = TelegramTemplate::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'tg_' . $this->faker->unique()->lexify('??????'),
            'description' => $this->faker->sentence(),
            'category' => 'notification',
            'body' => '<b>Bonjour {{ name }}</b>, votre rendez-vous est le {{ date }}.',
            'parse_mode' => 'HTML',
            'buttons' => null,
            'variables' => ['name', 'date'],
            'sample_data' => ['name' => 'Awa', 'date' => '12 octobre'],
            'status' => 'active',
            'is_active' => true,
        ];
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => ['business_id' => $business instanceof Business ? $business->id : $business]);
    }
}
