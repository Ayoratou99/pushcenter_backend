<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BusinessApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_list_all_businesses()
    {
        Business::factory()->count(3)->create();

        $response = $this->getJson('/api/businesses');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'phone',
                        'created_at',
                        'updated_at',
                    ]
                ]
            ])
            ->assertJson([
                'success' => true
            ]);
    }

    /** @test */
    public function it_can_create_a_business()
    {
        $businessData = [
            'name' => 'Test Business',
            'email' => 'test@business.com',
            'phone' => '+237670000000',
            'address' => '123 Test Street',
            'city' => 'Douala',
            'country' => 'Cameroon',
        ];

        $response = $this->postJson('/api/businesses', $businessData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'created_at',
                    'updated_at',
                ]
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Test Business',
                    'email' => 'test@business.com',
                ]
            ]);

        $this->assertDatabaseHas('businesses', [
            'name' => 'Test Business',
            'email' => 'test@business.com',
        ]);
    }

    /** @test */
    public function it_can_show_a_business()
    {
        $business = Business::factory()->create();

        $response = $this->getJson("/api/businesses/{$business->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'created_at',
                    'updated_at',
                ]
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $business->id,
                    'name' => $business->name,
                ]
            ]);
    }

    /** @test */
    public function it_can_update_a_business()
    {
        $business = Business::factory()->create();

        $updateData = [
            'name' => 'Updated Business Name',
            'email' => 'updated@business.com',
        ];

        $response = $this->putJson("/api/businesses/{$business->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Business Name',
                    'email' => 'updated@business.com',
                ]
            ]);

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'name' => 'Updated Business Name',
        ]);
    }

    /** @test */
    public function it_can_delete_a_business()
    {
        $business = Business::factory()->create();

        $response = $this->deleteJson("/api/businesses/{$business->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('businesses', [
            'id' => $business->id,
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_business()
    {
        $response = $this->postJson('/api/businesses', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_business()
    {
        $response = $this->getJson('/api/businesses/999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }
}

