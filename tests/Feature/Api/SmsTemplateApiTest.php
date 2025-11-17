<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Business;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SmsTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a business for testing
        $this->business = Business::factory()->create();
    }

    /** @test */
    public function it_can_list_sms_templates()
    {
        SmsTemplate::factory()->count(3)->create(['business_id' => $this->business->id]);

        $response = $this->getJson('/api/sms-templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'business_id',
                            'name',
                            'message',
                            'category',
                            'status',
                            'created_at'
                        ]
                    ]
                ]
            ]);
    }

    /** @test */
    public function it_can_create_sms_template()
    {
        $templateData = [
            'business_id' => $this->business->id,
            'name' => 'Welcome SMS',
            'description' => 'Welcome message for new users',
            'message' => 'Hello {{name}}, welcome to our service! Reply STOP to unsubscribe.',
            'variables' => ['name'],
            'category' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'sender_id' => 'MyBrand',
        ];

        $response = $this->postJson('/api/sms-templates', $templateData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'business_id',
                    'name',
                    'message',
                ]
            ]);

        $this->assertDatabaseHas('sms_templates', [
            'name' => 'Welcome SMS',
            'business_id' => $this->business->id,
        ]);
    }

    /** @test */
    public function it_can_show_sms_template()
    {
        $template = SmsTemplate::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Test SMS'
        ]);

        $response = $this->getJson("/api/sms-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $template->id,
                    'name' => 'Test SMS',
                ]
            ]);
    }

    /** @test */
    public function it_can_update_sms_template()
    {
        $template = SmsTemplate::factory()->create([
            'business_id' => $this->business->id,
            'message' => 'Old message'
        ]);

        $updateData = [
            'message' => 'Updated message content',
            'name' => 'Updated SMS Template'
        ];

        $response = $this->putJson("/api/sms-templates/{$template->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'SMS template updated successfully'
            ]);

        $this->assertDatabaseHas('sms_templates', [
            'id' => $template->id,
            'message' => 'Updated message content',
        ]);
    }

    /** @test */
    public function it_can_delete_sms_template()
    {
        $template = SmsTemplate::factory()->create([
            'business_id' => $this->business->id
        ]);

        $response = $this->deleteJson("/api/sms-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'SMS template deleted successfully'
            ]);

        $this->assertSoftDeleted('sms_templates', [
            'id' => $template->id
        ]);
    }

    /** @test */
    public function it_can_activate_sms_template()
    {
        $template = SmsTemplate::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => false
        ]);

        $response = $this->postJson("/api/sms-templates/{$template->id}/activate");

        $response->assertStatus(200);

        $this->assertDatabaseHas('sms_templates', [
            'id' => $template->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_can_deactivate_sms_template()
    {
        $template = SmsTemplate::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true
        ]);

        $response = $this->postJson("/api/sms-templates/{$template->id}/deactivate");

        $response->assertStatus(200);

        $this->assertDatabaseHas('sms_templates', [
            'id' => $template->id,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function it_validates_required_fields()
    {
        $response = $this->postJson('/api/sms-templates', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['business_id', 'name', 'message', 'category']);
    }

    /** @test */
    public function it_validates_message_max_length()
    {
        $response = $this->postJson('/api/sms-templates', [
            'business_id' => $this->business->id,
            'name' => 'Test',
            'message' => str_repeat('a', 1531), // Over 10 SMS segments
            'category' => 'marketing'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_template()
    {
        $response = $this->getJson('/api/sms-templates/99999');

        $response->assertStatus(404);
    }
}

