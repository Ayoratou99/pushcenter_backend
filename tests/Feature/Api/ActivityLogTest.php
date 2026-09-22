<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every console action is recorded; each manager only sees its perimeter.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::factory()->create(['name' => 'Guichet ANINF']);
        $this->other = Business::factory()->create(['name' => 'Autre application']);
    }

    public function test_a_console_action_is_recorded_with_its_context(): void
    {
        $manager = $this->restrictedManager([$this->mine->id], ['name' => 'Awa Ndong']);
        $template = EmailTemplate::factory()->forBusiness($this->mine)->create(['name' => 'Bienvenue']);

        $this->actingAsUser($manager)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'User-Agent' => 'PHPUnit'])
            ->putJson("/api/v1/email-templates/{$template->id}", ['name' => 'Bienvenue 2', 'subject' => 'Bonjour'])
            ->assertOk();

        $log = ActivityLog::sole();
        $this->assertSame('email_template.updated', $log->action);
        $this->assertSame('Updated email template Bienvenue', $log->description);
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame('Awa Ndong', $log->user_name);
        $this->assertSame($this->mine->id, $log->business_id);
        $this->assertSame('Guichet ANINF', $log->business_name);
        $this->assertSame('email_template', $log->subject_type);
        $this->assertSame($template->id, $log->subject_id);
        $this->assertSame('203.0.113.7', $log->ip_address);
        $this->assertSame('PUT', $log->method);
        $this->assertSame(['name' => 'Bienvenue 2', 'subject' => 'Bonjour'], $log->properties['input']);
    }

    public function test_secrets_never_reach_the_log(): void
    {
        $this->actingAsUser($this->globalManager())
            ->putJson("/api/v1/businesses/{$this->mine->id}/whatsapp-settings", [
                'api_key' => 'ak_live_123',
                'api_secret' => 'as_super_secret',
            ])->assertOk();

        $log = ActivityLog::where('action', 'whatsapp_settings.updated')->sole();
        $this->assertSame('***', $log->properties['input']['api_secret']);
        $this->assertSame('***', $log->properties['input']['api_key']);
        $this->assertStringNotContainsString('as_super_secret', json_encode($log->toArray()));
    }

    public function test_a_refused_action_is_not_recorded(): void
    {
        $manager = $this->restrictedManager([$this->mine->id]);
        $foreign = EmailTemplate::factory()->forBusiness($this->other)->create();

        $this->actingAsUser($manager)->putJson("/api/v1/email-templates/{$foreign->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAsUser($manager)->postJson('/api/v1/email-templates', [])->assertStatus(422);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_a_deleted_subject_keeps_its_name(): void
    {
        $template = EmailTemplate::factory()->forBusiness($this->mine)->create(['name' => 'Ancien modèle']);

        $this->actingAsUser($this->globalManager())->deleteJson("/api/v1/email-templates/{$template->id}")->assertOk();

        $this->assertSame('Deleted email template Ancien modèle', ActivityLog::sole()->description);
    }

    public function test_a_creation_points_at_the_new_subject(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/businesses', ['name' => 'Nouvelle application', 'email' => 'nouvelle@aninf.test'])
            ->assertCreated()
            ->json('data.business.id');

        $log = ActivityLog::where('action', 'business.created')->sole();
        $this->assertSame($id, $log->subject_id);
        $this->assertSame($id, $log->business_id);
        $this->assertSame('Created application Nouvelle application', $log->description);
    }

    public function test_sign_ins_are_recorded(): void
    {
        $user = User::factory()->create(['email' => 'awa@aninf.test', 'password' => 'Secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'awa@aninf.test', 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@aninf.test', 'password' => 'wrong'])->assertStatus(401);

        $failed = ActivityLog::where('action', 'account.login_failed')->orderBy('id')->get();
        $this->assertCount(2, $failed);
        $this->assertSame($user->id, $failed[0]->user_id);
        $this->assertNull($failed[1]->user_id);
        $this->assertSame('nobody@aninf.test', $failed[1]->user_email);

        // Without Google Authenticator the user is sent to the setup first:
        // no session yet, so no sign-in recorded.
        $this->postJson('/api/v1/auth/login', ['email' => 'awa@aninf.test', 'password' => 'Secret123'])->assertOk();
        $this->assertSame(0, ActivityLog::where('action', 'account.login')->count());
    }

    public function test_a_restricted_manager_sees_its_perimeter_only(): void
    {
        $manager = $this->restrictedManager([$this->mine->id]);
        $colleague = $this->restrictedManager([$this->mine->id]);
        $stranger = $this->restrictedManager([$this->other->id]);

        $this->actingAsUser($colleague)->putJson("/api/v1/businesses/{$this->mine->id}", ['name' => 'Guichet 2'])->assertOk();
        $this->actingAsUser($stranger)->putJson("/api/v1/businesses/{$this->other->id}", ['name' => 'Autre 2'])->assertOk();
        $this->actingAsUser($colleague)->putJson('/api/v1/auth/profile', ['name' => 'Collègue'])->assertOk();
        $this->actingAsUser($stranger)->putJson('/api/v1/auth/profile', ['name' => 'Inconnu'])->assertOk();

        $seen = $this->actingAsUser($manager)->getJson('/api/v1/activities')->assertOk()->json('data.data');

        $this->assertEqualsCanonicalizing(
            ['business.updated', 'account.profile_updated'],
            array_column($seen, 'action')
        );
        $this->assertSame([$colleague->id, $colleague->id], array_column($seen, 'user_id'));

        // Admins see everything.
        $this->actingAsAdmin();
        $this->assertCount(4, $this->getJson('/api/v1/activities')->json('data.data'));
    }

    public function test_the_log_can_be_filtered(): void
    {
        $admin = $this->actingAsAdmin();
        $template = EmailTemplate::factory()->forBusiness($this->mine)->create();

        $this->putJson("/api/v1/email-templates/{$template->id}", ['name' => 'A'])->assertOk();
        $this->postJson("/api/v1/email-templates/{$template->id}/deactivate")->assertOk();
        $this->putJson("/api/v1/businesses/{$this->other->id}", ['name' => 'B'])->assertOk();

        $this->getJson('/api/v1/activities?action_group=email_template')->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/activities?action=email_template.deactivated')->assertJsonPath('data.total', 1);
        $this->getJson("/api/v1/activities?business_id={$this->other->id}")->assertJsonPath('data.total', 1);
        $this->getJson("/api/v1/activities?user_id={$admin->id}&search=deactivated")->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/activities?start_date=' . now()->addDay()->toDateString())->assertJsonPath('data.total', 0);

        $this->getJson('/api/v1/activities/actions')
            ->assertOk()
            ->assertJsonFragment(['whatsapp_template.submitted'])
            ->assertJsonFragment(['account.login']);
    }

    public function test_exports_are_recorded(): void
    {
        $this->actingAsAdmin();
        $template = EmailTemplate::factory()->forBusiness($this->mine)->create(['name' => 'Facture']);

        $this->get("/api/v1/templates/email/{$template->id}/export?format=json")->assertOk();

        $log = ActivityLog::where('action', 'template.exported')->sole();
        $this->assertSame('Exported email template Facture', $log->description);
        $this->assertSame($this->mine->id, $log->business_id);
    }
}
