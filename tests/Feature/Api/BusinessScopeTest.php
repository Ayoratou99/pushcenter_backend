<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A manager restricted to a set of applications must not reach another one,
 * neither through the lists nor by guessing a record id.
 */
class BusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::factory()->create(['name' => 'Mine']);
        $this->theirs = Business::factory()->create(['name' => 'Theirs']);

        $this->actingAsUser($this->restrictedManager([$this->mine->id]));
    }

    public function test_reading_another_application_is_refused(): void
    {
        $this->getJson("/api/v1/businesses/{$this->theirs->id}")->assertStatus(403);
        $this->getJson("/api/v1/businesses/{$this->mine->id}")->assertOk();
    }

    public function test_updating_another_application_is_refused(): void
    {
        $this->putJson("/api/v1/businesses/{$this->theirs->id}", ['name' => 'Hijacked'])->assertStatus(403);

        $this->assertSame('Theirs', $this->theirs->fresh()->name);
    }

    public function test_deleting_another_application_is_refused(): void
    {
        $this->deleteJson("/api/v1/businesses/{$this->theirs->id}")->assertStatus(403);

        $this->assertNotSoftDeleted('businesses', ['id' => $this->theirs->id]);
    }

    public function test_reading_another_application_stats_is_refused(): void
    {
        $this->getJson("/api/v1/businesses/{$this->theirs->id}/stats")->assertStatus(403);
    }

    public function test_regenerating_another_application_credentials_is_refused(): void
    {
        $secret = $this->theirs->app_secret;

        $this->postJson("/api/v1/businesses/{$this->theirs->id}/regenerate-credentials")->assertStatus(403);

        $this->assertSame($secret, $this->theirs->fresh()->app_secret);
    }

    public function test_reading_a_template_of_another_application_is_refused(): void
    {
        $theirTemplate = EmailTemplate::factory()->forBusiness($this->theirs)->create();
        $myTemplate = EmailTemplate::factory()->forBusiness($this->mine)->create();

        $this->getJson("/api/v1/email-templates/{$theirTemplate->id}")->assertStatus(403);
        $this->getJson("/api/v1/email-templates/{$myTemplate->id}")->assertOk();
    }

    public function test_editing_a_template_of_another_application_is_refused(): void
    {
        $theirTemplate = EmailTemplate::factory()->forBusiness($this->theirs)->create(['name' => 'Theirs']);

        $this->putJson("/api/v1/email-templates/{$theirTemplate->id}", ['name' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/v1/email-templates/{$theirTemplate->id}")->assertStatus(403);
        $this->postJson("/api/v1/email-templates/{$theirTemplate->id}/activate")->assertStatus(403);

        $this->assertSame('Theirs', $theirTemplate->fresh()->name);
    }

    public function test_creating_a_template_in_another_application_is_refused(): void
    {
        $this->postJson('/api/v1/email-templates', [
            'business_id' => $this->theirs->id,
            'name' => 'Sneaky',
            'subject' => 'Hello',
            'category' => 'marketing',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('email_templates', ['name' => 'Sneaky']);
    }

    public function test_creating_a_template_in_an_allowed_application_works(): void
    {
        $this->postJson('/api/v1/email-templates', [
            'business_id' => $this->mine->id,
            'name' => 'Legit',
            'subject' => 'Hello',
            'category' => 'marketing',
        ])->assertStatus(201);
    }

    public function test_reading_a_message_of_another_application_is_refused(): void
    {
        $theirMessage = Message::factory()->create(['business_id' => $this->theirs->id]);
        $myMessage = Message::factory()->create(['business_id' => $this->mine->id]);

        $this->getJson("/api/v1/messages/{$theirMessage->id}")->assertStatus(403);
        $this->getJson("/api/v1/messages/{$myMessage->id}")->assertOk();
    }

    public function test_exporting_a_template_of_another_application_is_refused(): void
    {
        $theirTemplate = EmailTemplate::factory()->forBusiness($this->theirs)->create();

        $this->getJson("/api/v1/templates/email/{$theirTemplate->id}/export?download=0")->assertStatus(403);
    }

    public function test_a_bulk_export_silently_drops_other_applications(): void
    {
        $mine = EmailTemplate::factory()->forBusiness($this->mine)->create();
        $theirs = EmailTemplate::factory()->forBusiness($this->theirs)->create();

        $document = $this->postJson('/api/v1/templates/export', [
            'type' => 'email',
            'ids' => [$mine->id, $theirs->id],
            'download' => false,
        ])->assertOk()->json('data.document');

        $this->assertCount(1, $document['templates']);
        $this->assertSame($mine->name, $document['templates'][0]['name']);
    }

    public function test_importing_into_another_application_is_refused(): void
    {
        $mine = EmailTemplate::factory()->forBusiness($this->mine)->create();

        $content = $this->getJson("/api/v1/templates/email/{$mine->id}/export?download=0")->json('data.content');

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $this->theirs->id,
            'content' => $content,
        ])->assertStatus(403);

        $this->assertSame(0, EmailTemplate::where('business_id', $this->theirs->id)->count());
        $this->assertDatabaseCount('email_templates', 1);
    }

    public function test_a_global_manager_is_not_restricted(): void
    {
        $this->actingAsUser($this->globalManager());

        $this->getJson("/api/v1/businesses/{$this->theirs->id}")->assertOk();
        $this->getJson("/api/v1/businesses/{$this->mine->id}")->assertOk();
    }

    public function test_an_admin_is_not_restricted(): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/v1/businesses/{$this->theirs->id}")->assertOk();
    }

    public function test_an_unknown_id_still_answers_404(): void
    {
        $this->getJson('/api/v1/businesses/999999')->assertStatus(404);
        $this->getJson('/api/v1/email-templates/999999')->assertStatus(404);
    }
}
