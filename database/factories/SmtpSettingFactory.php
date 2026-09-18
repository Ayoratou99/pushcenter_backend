<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SmtpSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmtpSettingFactory extends Factory
{
    protected $model = SmtpSetting::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Primary SMTP',
            'description' => 'Main outbound configuration',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'password' => 'smtp-password',
            'from_email' => 'no-reply@example.test',
            'from_name' => 'Example App',
            'timeout' => 30,
            'verify_peer' => true,
            'is_active' => true,
            'is_default' => true,
            'test_status' => 'not_tested',
            'messages_sent' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function secondary(): static
    {
        return $this->state(fn () => ['is_default' => false, 'name' => 'Secondary SMTP']);
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }
}
