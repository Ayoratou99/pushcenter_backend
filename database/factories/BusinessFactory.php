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
            'phone_number' => $this->faker->phoneNumber(),
            'country_code' => '+237',
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state_province' => $this->faker->word(),
            'country' => $this->faker->country(),
            'postal_code' => $this->faker->postcode(),
            'website' => $this->faker->url(),
            'description' => $this->faker->paragraph(),
            'timezone' => 'Africa/Douala',
            'status' => 'active',
            'verification_status' => 'pending',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
