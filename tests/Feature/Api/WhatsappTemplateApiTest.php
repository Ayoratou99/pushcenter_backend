<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Business;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WhatsappTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a business for testing
        $this->business = Business::factory()->create();
    }

    /** @test */
    public function it_can_list_whatsapp_templates()
    {
        WhatsappTemplate::factory()->count(3)->create(['business_id' => $this->business->id]);

        $response = $this->getJson('/api/whatsapp-templates');

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
                            'display_name',
                            'body',
                            'status',
                            'created_at'
                        ]
                    ]
                ]
            ]);
    }

    /** @test */
    public function it_can_create_whatsapp_template()
    {
        $templateData = [
            'business_id' => $this->business->id,
            'name' => 'welcome_message',
            'display_name' => 'Welcome Message',
            'description' => 'Greet new users',
            'language' => 'en',
            'category' => 'MARKETING',
            'body' => 'Hello {{1}}, welcome to our service!',
            'variables' => ['name'],
            'status' => 'draft',
            'is_active' => true,
            'allow_variables' => true,
        ];

        $response = $this->postJson('/api/whatsapp-templates', $templateData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'business_id',
                    'name',
                    'display_name',
                    'body',
                ]
            ]);

        $this->assertDatabaseHas('whatsapp_templates', [
            'name' => 'welcome_message',
            'business_id' => $this->business->id,
        ]);
    }

    /** @test */
    public function it_can_show_whatsapp_template()
    {
        $template = WhatsappTemplate::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'test_template'
        ]);

        $response = $this->getJson("/api/whatsapp-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $template->id,
                    'name' => 'test_template',
                ]
            ]);
    }

    /** @test */
    public function it_can_update_whatsapp_template()
    {
        $template = WhatsappTemplate::factory()->create([
            'business_id' => $this->business->id,
            'body' => 'Old body'
        ]);

        $updateData = [
            'body' => 'Updated body content',
            'display_name' => 'Updated Name'
        ];

        $response = $this->putJson("/api/whatsapp-templates/{$template->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'WhatsApp template updated successfully'
            ]);

        $this->assertDatabaseHas('whatsapp_templates', [
            'id' => $template->id,
            'body' => 'Updated body content',
        ]);
    }

    /** @test */
    public function it_can_delete_whatsapp_template()
    {
        $template = WhatsappTemplate::factory()->create([
            'business_id' => $this->business->id
        ]);

        $response = $this->deleteJson("/api/whatsapp-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'WhatsApp template deleted successfully'
            ]);

        $this->assertSoftDeleted('whatsapp_templates', [
            'id' => $template->id
        ]);
    }

    /** @test */
    public function it_can_activate_whatsapp_template()
    {
        $template = WhatsappTemplate::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => false
        ]);

        $response = $this->postJson("/api/whatsapp-templates/{$template->id}/activate");

        $response->assertStatus(200);

        $this->assertDatabaseHas('whatsapp_templates', [
            'id' => $template->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_can_deactivate_whatsapp_template()
    {
        $template = WhatsappTemplate::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true
        ]);

        $response = $this->postJson("/api/whatsapp-templates/{$template->id}/deactivate");

        $response->assertStatus(200);

        $this->assertDatabaseHas('whatsapp_templates', [
            'id' => $template->id,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function it_validates_required_fields()
    {
        $response = $this->postJson('/api/whatsapp-templates', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['business_id', 'name', 'display_name', 'body']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_template()
    {
        $response = $this->getJson('/api/whatsapp-templates/99999');

        $response->assertStatus(404);
    }
}

