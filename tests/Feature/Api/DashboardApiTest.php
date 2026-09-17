<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Business;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsUser($this->globalManager());
        $this->business = Business::factory()->create();
    }

    #[Test]
    public function it_can_get_dashboard_stats()
    {
        // Create some test messages
        Message::factory()->count(5)->create([
            'business_id' => $this->business->id,
            'status' => 'sent',
        ]);
        
        Message::factory()->count(2)->create([
            'business_id' => $this->business->id,
            'status' => 'failed',
        ]);

        $response = $this->getJson('/api/v1/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_messages',
                    'sent_messages',
                    'failed_messages',
                    'pending_messages',
                    'total_businesses',
                    'total_cost',
                ]
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    #[Test]
    public function it_can_get_recent_messages()
    {
        Message::factory()->count(5)->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->getJson('/api/v1/dashboard/recent-messages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'message_id',
                        'message_type',
                        'status',
                        'created_at',
                    ]
                ]
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    #[Test]
    public function it_can_get_message_trends()
    {
        Message::factory()->count(10)->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->getJson('/api/v1/dashboard/message-trends');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    #[Test]
    public function it_can_get_cost_analysis()
    {
        Message::factory()->count(10)->create([
            'business_id' => $this->business->id,
            'cost' => 100,
            'currency' => 'XAF',
        ]);

        $response = $this->getJson('/api/v1/dashboard/cost-analysis');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_cost',
                    'cost_by_type',
                    'cost_by_date',
                ]
            ])
            ->assertJson([
                'success' => true,
            ]);
    }
}

