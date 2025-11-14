<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => $this->faker->country(),
            'postal_code' => $this->faker->postcode(),
            'website' => $this->faker->url(),
            'description' => $this->faker->paragraph(),
            'logo_url' => $this->faker->imageUrl(200, 200, 'business'),
            'timezone' => $this->faker->timezone(),
            'currency' => 'XAF',
            'status' => $this->faker->randomElement(['active', 'inactive', 'suspended']),
        ];
    }
}

