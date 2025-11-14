<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Business;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmailTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
    }

    /** @test */
    public function it_can_list_all_email_templates()
    {
        EmailTemplate::factory()->count(3)->create(['business_id' => $this->business->id]);

        $response = $this->getJson('/api/email-templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'business_id',
                        'name',
                        'subject',
                        'content',
                        'status',
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
    public function it_can_create_an_email_template()
    {
        $templateData = [
            'business_id' => $this->business->id,
            'name' => 'Welcome Email',
            'subject' => 'Welcome to Our Service',
            'content' => '<h1>Welcome!</h1><p>Thank you for joining us.</p>',
            'sender_name' => 'Support Team',
            'sender_email' => 'support@example.com',
            'status' => 'draft',
            'category' => 'transactional',
        ];

        $response = $this->postJson('/api/email-templates', $templateData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Welcome Email',
                    'subject' => 'Welcome to Our Service',
                ]
            ]);

        $this->assertDatabaseHas('email_templates', [
            'name' => 'Welcome Email',
            'business_id' => $this->business->id,
        ]);
    }

    /** @test */
    public function it_can_show_an_email_template()
    {
        $template = EmailTemplate::factory()->create(['business_id' => $this->business->id]);

        $response = $this->getJson("/api/email-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $template->id,
                    'name' => $template->name,
                ]
            ]);
    }

    /** @test */
    public function it_can_update_an_email_template()
    {
        $template = EmailTemplate::factory()->create(['business_id' => $this->business->id]);

        $updateData = [
            'name' => 'Updated Template',
            'subject' => 'Updated Subject',
        ];

        $response = $this->putJson("/api/email-templates/{$template->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Template',
                ]
            ]);

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'name' => 'Updated Template',
        ]);
    }

    /** @test */
    public function it_can_delete_an_email_template()
    {
        $template = EmailTemplate::factory()->create(['business_id' => $this->business->id]);

        $response = $this->deleteJson("/api/email-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('email_templates', [
            'id' => $template->id,
        ]);
    }

    /** @test */
    public function it_can_activate_an_email_template()
    {
        $template = EmailTemplate::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/email-templates/{$template->id}/activate");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'active',
                ]
            ]);

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'status' => 'active',
        ]);
    }

    /** @test */
    public function it_can_deactivate_an_email_template()
    {
        $template = EmailTemplate::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/email-templates/{$template->id}/deactivate");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'archived',
                ]
            ]);

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'status' => 'archived',
        ]);
    }
}

