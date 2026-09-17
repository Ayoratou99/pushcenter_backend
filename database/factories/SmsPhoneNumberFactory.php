<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SmsPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmsPhoneNumberFactory extends Factory
{
    protected $model = SmsPhoneNumber::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'phone_number' => '+2376' . $this->faker->unique()->numerify('########'),
            'sender_id' => 'ANINFPUSH',
            'provider' => 'twilio',
            'status' => 'active',
            'can_send_sms' => true,
        ];
    }
}
