<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TemplateTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser($this->globalManager());
    }

    /* ------------------------------- Export -------------------------------- */

    public function test_an_email_template_is_exported_as_json(): void
    {
        $template = EmailTemplate::factory()->create(['name' => 'Welcome mail']);

        $response = $this->getJson("/api/v1/templates/email/{$template->id}/export?format=json&download=0")
            ->assertOk();

        $document = $response->json('data.document');

        $this->assertSame('aninfpush.template', $document['format']);
        $this->assertSame('email', $document['type']);
        $this->assertSame('Welcome mail', $document['template']['name']);
        $this->assertSame($template->subject, $document['template']['subject']);
        $this->assertStringEndsWith('.json', $response->json('data.filename'));

        // Identifiers and usage counters never travel with the template.
        $this->assertArrayNotHasKey('id', $document['template']);
        $this->assertArrayNotHasKey('business_id', $document['template']);
        $this->assertArrayNotHasKey('usage_count', $document['template']);
    }

    public function test_a_template_is_exported_as_txt(): void
    {
        $template = SmsTemplate::factory()->create(['name' => 'OTP code']);

        $response = $this->getJson("/api/v1/templates/sms/{$template->id}/export?format=txt&download=0")
            ->assertOk();

        $content = $response->json('data.content');

        $this->assertStringStartsWith('# AninfPush template export', $content);
        $this->assertStringContainsString('OTP code', $content);
        $this->assertStringEndsWith('.txt', $response->json('data.filename'));
    }

    public function test_exporting_downloads_a_file_by_default(): void
    {
        $template = EmailTemplate::factory()->create();

        $this->get("/api/v1/templates/email/{$template->id}/export")
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8')
            ->assertDownload();
    }

    public function test_exporting_an_unknown_type_is_rejected(): void
    {
        $template = EmailTemplate::factory()->create();

        // The route only accepts email|sms|whatsapp, so an unknown type 404s.
        $this->getJson("/api/v1/templates/pigeon/{$template->id}/export")->assertStatus(404);
    }

    public function test_exporting_a_missing_template_returns_404(): void
    {
        $this->getJson('/api/v1/templates/email/999999/export')->assertStatus(404);
    }

    public function test_several_templates_are_exported_in_one_bundle(): void
    {
        $templates = EmailTemplate::factory()->count(3)->create();

        $document = $this->postJson('/api/v1/templates/export', [
            'type' => 'email',
            'ids' => $templates->pluck('id')->all(),
            'download' => false,
        ])->assertOk()->json('data.document');

        $this->assertCount(3, $document['templates']);
    }

    /* ------------------------------- Import -------------------------------- */

    private function exportContent(string $type, int $id, string $format = 'json'): string
    {
        return $this->getJson("/api/v1/templates/{$type}/{$id}/export?format={$format}&download=0")
            ->json('data.content');
    }

    public function test_a_json_export_is_imported_into_the_chosen_application(): void
    {
        $source = EmailTemplate::factory()->create(['name' => 'Newsletter']);
        $target = Business::factory()->create(['name' => 'Target App']);

        $content = $this->exportContent('email', $source->id);

        $response = $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => $content,
        ])->assertStatus(201);

        $this->assertSame('email', $response->json('data.type'));
        $this->assertSame(1, $response->json('data.imported'));

        $imported = EmailTemplate::where('business_id', $target->id)->first();
        $this->assertSame('Newsletter', $imported->name);
        $this->assertSame($source->subject, $imported->subject);
        // Imported templates always land as inactive drafts.
        $this->assertSame('draft', $imported->status);
        $this->assertFalse($imported->is_active);
        $this->assertSame(0, $imported->usage_count);
    }

    public function test_a_txt_export_is_imported_too(): void
    {
        $source = SmsTemplate::factory()->create(['name' => 'Reminder']);
        $target = Business::factory()->create();

        $content = $this->exportContent('sms', $source->id, 'txt');

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => $content,
        ])->assertStatus(201);

        $this->assertDatabaseHas('sms_templates', [
            'business_id' => $target->id,
            'name' => 'Reminder',
            'status' => 'draft',
        ]);
    }

    public function test_importing_from_an_uploaded_file(): void
    {
        $source = WhatsappTemplate::factory()->create();
        $target = Business::factory()->create();

        $content = $this->exportContent('whatsapp', $source->id);
        $file = UploadedFile::fake()->createWithContent('template.json', $content);

        $this->post('/api/v1/templates/import', [
            'business_id' => $target->id,
            'file' => $file,
        ])->assertStatus(201);

        $this->assertDatabaseHas('whatsapp_templates', [
            'business_id' => $target->id,
            'name' => $source->name,
        ]);
    }

    public function test_importing_only_needs_the_target_application(): void
    {
        $source = EmailTemplate::factory()->create();
        $target = Business::factory()->create();

        // No `type` sent: it comes from the file itself.
        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => $this->exportContent('email', $source->id),
        ])->assertStatus(201)->assertJsonPath('data.type', 'email');
    }

    public function test_importing_requires_a_business(): void
    {
        $source = EmailTemplate::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'content' => $this->exportContent('email', $source->id),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['business_id']]);
    }

    public function test_importing_rejects_an_unknown_business(): void
    {
        $source = EmailTemplate::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'business_id' => 999999,
            'content' => $this->exportContent('email', $source->id),
        ])->assertStatus(422);
    }

    public function test_importing_rejects_a_file_that_is_not_an_export(): void
    {
        $target = Business::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => 'this is definitely not json',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['file']]);
    }

    public function test_importing_rejects_an_empty_file(): void
    {
        $target = Business::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => '   ',
        ])->assertStatus(422);
    }

    public function test_a_name_clash_is_resolved_instead_of_failing(): void
    {
        $business = Business::factory()->create();
        EmailTemplate::factory()->forBusiness($business)->create(['name' => 'Welcome']);

        $source = EmailTemplate::factory()->create(['name' => 'Welcome']);

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $business->id,
            'content' => $this->exportContent('email', $source->id),
        ])->assertStatus(201);

        $this->assertDatabaseHas('email_templates', [
            'business_id' => $business->id,
            'name' => 'Welcome (2)',
        ]);
    }

    public function test_a_single_import_can_be_renamed(): void
    {
        $source = EmailTemplate::factory()->create(['name' => 'Original']);
        $target = Business::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'name' => 'Renamed on import',
            'content' => $this->exportContent('email', $source->id),
        ])->assertStatus(201);

        $this->assertDatabaseHas('email_templates', [
            'business_id' => $target->id,
            'name' => 'Renamed on import',
        ]);
    }

    public function test_a_bundle_imports_every_template(): void
    {
        $templates = SmsTemplate::factory()->count(3)->create();
        $target = Business::factory()->create();

        $bundle = $this->postJson('/api/v1/templates/export', [
            'type' => 'sms',
            'ids' => $templates->pluck('id')->all(),
            'download' => false,
        ])->json('data.content');

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => $bundle,
        ])->assertStatus(201)->assertJsonPath('data.imported', 3);

        $this->assertSame(3, SmsTemplate::where('business_id', $target->id)->count());
    }

    public function test_the_preview_endpoint_describes_the_file_without_saving(): void
    {
        $source = EmailTemplate::factory()->create(['name' => 'Preview me']);

        $before = EmailTemplate::count();

        $this->postJson('/api/v1/templates/import/preview', [
            'content' => $this->exportContent('email', $source->id),
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'email')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.templates.0.name', 'Preview me');

        $this->assertSame($before, EmailTemplate::count());
    }

    public function test_the_type_is_guessed_from_a_bare_template_object(): void
    {
        $target = Business::factory()->create();

        // A hand written payload, with no envelope at all.
        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => json_encode([
                'name' => 'Hand written',
                'message' => 'Your code is {{code}}',
            ]),
        ])->assertStatus(201)->assertJsonPath('data.type', 'sms');

        $this->assertDatabaseHas('sms_templates', [
            'business_id' => $target->id,
            'name' => 'Hand written',
        ]);
    }

    public function test_an_explicit_type_overrides_the_document(): void
    {
        $target = Business::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'type' => 'sms',
            'content' => json_encode(['name' => 'Forced sms', 'message' => 'hello']),
        ])->assertStatus(201)->assertJsonPath('data.type', 'sms');
    }

    public function test_an_undecidable_payload_is_rejected(): void
    {
        $target = Business::factory()->create();

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => json_encode(['name' => 'Nothing recognisable']),
        ])->assertStatus(422);
    }

    public function test_transfer_endpoints_require_authentication(): void
    {
        $template = EmailTemplate::factory()->create();

        $this->withHeader('Authorization', 'Bearer invalid')
            ->getJson("/api/v1/templates/email/{$template->id}/export")
            ->assertStatus(401);
    }
}
