<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\SmsMessage;
use App\Models\SmsPhoneNumber;
use App\Models\SmsTemplate;
use App\Models\Template;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The list endpoints must let every filter be combined, not pick a single one.
 */
class FilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser($this->globalManager());
    }

    /* ---------------------------- Email templates -------------------------- */

    public function test_email_template_filters_combine(): void
    {
        $business = Business::factory()->create(['name' => 'Acme']);
        $other = Business::factory()->create(['name' => 'Globex']);

        EmailTemplate::factory()->forBusiness($business)->active()->create([
            'name' => 'Promo spring', 'category' => 'marketing',
        ]);
        EmailTemplate::factory()->forBusiness($business)->create([
            'name' => 'Promo winter', 'category' => 'marketing', 'status' => 'draft',
        ]);
        EmailTemplate::factory()->forBusiness($other)->active()->create([
            'name' => 'Promo autumn', 'category' => 'marketing',
        ]);

        // business + status + category + search, all at once.
        $data = $this->getJson(
            "/api/v1/email-templates?business_id={$business->id}&status=active&category=marketing&search=Promo"
        )->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame('Promo spring', $data[0]['name']);
    }

    public function test_email_templates_can_be_searched_by_business_name(): void
    {
        $business = Business::factory()->create(['name' => 'Umbrella Corp']);
        EmailTemplate::factory()->forBusiness($business)->create();
        EmailTemplate::factory()->create();

        $data = $this->getJson('/api/v1/email-templates?search=Umbrella')->assertOk()->json('data.data');

        $this->assertCount(1, $data);
    }

    public function test_email_templates_accept_multiple_statuses(): void
    {
        $business = Business::factory()->create();
        EmailTemplate::factory()->forBusiness($business)->active()->create();
        EmailTemplate::factory()->forBusiness($business)->create(['status' => 'draft']);
        EmailTemplate::factory()->forBusiness($business)->archived()->create();

        $data = $this->getJson('/api/v1/email-templates?status_in=active,draft')->assertOk()->json('data.data');

        $this->assertCount(2, $data);
    }

    public function test_email_templates_filter_on_a_date_range(): void
    {
        $business = Business::factory()->create();
        EmailTemplate::factory()->forBusiness($business)->create(['created_at' => now()->subDays(30)]);
        EmailTemplate::factory()->forBusiness($business)->create(['created_at' => now()->subDay()]);

        $data = $this->getJson('/api/v1/email-templates?created_from=' . now()->subDays(7)->toDateString())
            ->assertOk()->json('data.data');

        $this->assertCount(1, $data);
    }

    public function test_email_templates_can_be_sorted(): void
    {
        $business = Business::factory()->create();
        EmailTemplate::factory()->forBusiness($business)->create(['name' => 'Bravo']);
        EmailTemplate::factory()->forBusiness($business)->create(['name' => 'Alpha']);

        $data = $this->getJson('/api/v1/email-templates?sort_by=name&sort_dir=asc')->assertOk()->json('data.data');

        $this->assertSame('Alpha', $data[0]['name']);
    }

    public function test_an_unknown_sort_column_falls_back_instead_of_failing(): void
    {
        EmailTemplate::factory()->count(2)->create();

        $this->getJson('/api/v1/email-templates?sort_by=;DROP TABLE users;--')->assertOk();
    }

    public function test_page_size_is_capped(): void
    {
        EmailTemplate::factory()->count(3)->create();

        $this->assertSame(100, $this->getJson('/api/v1/email-templates?per_page=100000')->json('data.per_page'));
    }

    /* ----------------------------- SMS templates --------------------------- */

    public function test_sms_template_filters_combine(): void
    {
        $business = Business::factory()->create();

        SmsTemplate::factory()->forBusiness($business)->active()->create([
            'name' => 'OTP login', 'category' => 'otp',
        ]);
        SmsTemplate::factory()->forBusiness($business)->create([
            'name' => 'Marketing blast', 'category' => 'marketing',
        ]);

        $data = $this->getJson("/api/v1/sms-templates?business_id={$business->id}&category=otp&status=active")
            ->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame('OTP login', $data[0]['name']);
    }

    public function test_sms_templates_search_the_message_body(): void
    {
        $business = Business::factory()->create();
        SmsTemplate::factory()->forBusiness($business)->create(['message' => 'Your verification pin is {{code}}']);
        SmsTemplate::factory()->forBusiness($business)->create(['message' => 'Big sale this weekend']);

        $data = $this->getJson('/api/v1/sms-templates?search=verification')->assertOk()->json('data.data');

        $this->assertCount(1, $data);
    }

    /* -------------------------- WhatsApp templates ------------------------- */

    public function test_whatsapp_template_filters_combine(): void
    {
        $business = Business::factory()->create();

        WhatsappTemplate::factory()->forBusiness($business)->approved()->create([
            'language' => 'fr', 'category' => 'MARKETING',
        ]);
        WhatsappTemplate::factory()->forBusiness($business)->create([
            'language' => 'en', 'category' => 'MARKETING',
        ]);

        $data = $this->getJson("/api/v1/whatsapp-templates?business_id={$business->id}&language=fr&status=approved")
            ->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame('fr', $data[0]['language']);
    }

    /* ------------------------------- Templates ----------------------------- */

    public function test_generic_template_filters_combine(): void
    {
        $business = Business::factory()->create();

        Template::factory()->create([
            'business_id' => $business->id, 'type' => 'email', 'status' => 'active', 'name' => 'Alpha',
        ]);
        Template::factory()->create([
            'business_id' => $business->id, 'type' => 'sms', 'status' => 'active', 'name' => 'Beta',
        ]);

        $data = $this->getJson("/api/v1/templates?business_id={$business->id}&type=email&status=active")
            ->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame('Alpha', $data[0]['name']);
    }

    /* -------------------------------- Messages ----------------------------- */

    public function test_message_filters_combine(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();

        Message::factory()->create([
            'business_id' => $business->id, 'message_type' => 'email', 'status' => 'delivered',
        ]);
        Message::factory()->create([
            'business_id' => $business->id, 'message_type' => 'sms', 'status' => 'delivered',
        ]);
        Message::factory()->create([
            'business_id' => $other->id, 'message_type' => 'email', 'status' => 'delivered',
        ]);

        $data = $this->getJson("/api/v1/messages?business_id={$business->id}&message_type=email&status=delivered")
            ->assertOk()->json('data.data');

        $this->assertCount(1, $data);
    }

    public function test_messages_filter_on_several_statuses(): void
    {
        $business = Business::factory()->create();
        Message::factory()->create(['business_id' => $business->id, 'status' => 'sent']);
        Message::factory()->create(['business_id' => $business->id, 'status' => 'failed']);
        Message::factory()->create(['business_id' => $business->id, 'status' => 'pending']);

        $data = $this->getJson('/api/v1/messages?status_in=sent,failed')->assertOk()->json('data.data');

        $this->assertCount(2, $data);
    }

    public function test_messages_can_be_filtered_by_the_template_used(): void
    {
        $business = Business::factory()->create();
        $template = Template::factory()->create(['business_id' => $business->id, 'type' => 'email']);

        $withTemplate = Message::factory()->create([
            'business_id' => $business->id, 'message_type' => 'email',
        ]);
        EmailMessage::create([
            'message_id' => $withTemplate->id,
            'template_id' => $template->id,
            'is_template' => true,
            'recipient_email' => 'someone@example.com',
            'subject' => 'Hello',
        ]);

        $freeForm = Message::factory()->create([
            'business_id' => $business->id, 'message_type' => 'email',
        ]);
        EmailMessage::create([
            'message_id' => $freeForm->id,
            'is_template' => false,
            'recipient_email' => 'other@example.com',
            'subject' => 'Ad hoc',
        ]);

        $data = $this->getJson("/api/v1/messages?template_id={$template->id}")->assertOk()->json('data.data');
        $this->assertCount(1, $data);
        $this->assertSame($withTemplate->id, $data[0]['id']);

        $this->assertCount(1, $this->getJson('/api/v1/messages?is_template=1')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/v1/messages?is_template=0')->json('data.data'));
    }

    public function test_messages_can_be_filtered_by_recipient_across_channels(): void
    {
        $business = Business::factory()->create();

        $email = Message::factory()->create(['business_id' => $business->id, 'message_type' => 'email']);
        EmailMessage::create([
            'message_id' => $email->id,
            'is_template' => false,
            'recipient_email' => 'target@example.com',
        ]);

        $sms = Message::factory()->create(['business_id' => $business->id, 'message_type' => 'sms']);
        SmsMessage::create([
            'message_id' => $sms->id,
            'is_template' => false,
            'recipient_number' => '+237690000001',
            'sms_phone_number_id' => SmsPhoneNumber::factory()->create(['business_id' => $business->id])->id,
        ]);

        $this->assertCount(1, $this->getJson('/api/v1/messages?recipient=target@example.com')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/v1/messages?recipient=690000001')->json('data.data'));
    }

    public function test_messages_filter_on_a_date_range_and_a_cost_range(): void
    {
        $business = Business::factory()->create();

        Message::factory()->create([
            'business_id' => $business->id, 'cost' => 10, 'created_at' => now()->subDays(20),
        ]);
        Message::factory()->create([
            'business_id' => $business->id, 'cost' => 500, 'created_at' => now()->subDay(),
        ]);

        $this->assertCount(
            1,
            $this->getJson('/api/v1/messages?start_date=' . now()->subDays(7)->toDateString())->json('data.data')
        );

        $this->assertCount(1, $this->getJson('/api/v1/messages?min_cost=100')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/v1/messages?max_cost=100')->json('data.data'));
    }

    public function test_messages_can_be_filtered_on_errors(): void
    {
        $business = Business::factory()->create();
        Message::factory()->failed()->create(['business_id' => $business->id]);
        Message::factory()->create(['business_id' => $business->id, 'error_message' => null]);

        $this->assertCount(1, $this->getJson('/api/v1/messages?has_error=1')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/v1/messages?has_error=0')->json('data.data'));
    }

    /* ------------------------------- Businesses ---------------------------- */

    public function test_business_filters_combine(): void
    {
        Business::factory()->create(['name' => 'Acme Cameroon', 'city' => 'Douala', 'status' => 'active']);
        Business::factory()->create(['name' => 'Acme Nigeria', 'city' => 'Lagos', 'status' => 'active']);
        Business::factory()->create(['name' => 'Other', 'city' => 'Douala', 'status' => 'inactive']);

        $data = $this->getJson('/api/v1/businesses?search=Acme&city=Douala&status=active')
            ->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame('Acme Cameroon', $data[0]['name']);
    }

    public function test_businesses_can_be_searched_by_app_id(): void
    {
        $business = Business::factory()->create();
        Business::factory()->create();

        $data = $this->getJson('/api/v1/businesses?search=' . $business->app_id)->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame($business->id, $data[0]['id']);
    }

    /* --------------------------- Manager scoping --------------------------- */

    public function test_a_restricted_manager_only_sees_their_applications(): void
    {
        $mine = Business::factory()->create(['name' => 'Mine']);
        Business::factory()->create(['name' => 'Not mine']);

        EmailTemplate::factory()->forBusiness($mine)->create();
        EmailTemplate::factory()->create();

        $manager = $this->restrictedManager([$mine->id]);
        $this->actingAsUser($manager);

        $businesses = $this->getJson('/api/v1/businesses')->assertOk()->json('data.data');
        $this->assertCount(1, $businesses);
        $this->assertSame('Mine', $businesses[0]['name']);

        $templates = $this->getJson('/api/v1/email-templates')->assertOk()->json('data.data');
        $this->assertCount(1, $templates);
        $this->assertSame($mine->id, $templates[0]['business_id']);
    }

    public function test_a_restricted_manager_cannot_reach_another_application_by_filtering(): void
    {
        $mine = Business::factory()->create();
        $theirs = Business::factory()->create();
        EmailTemplate::factory()->forBusiness($theirs)->create();

        $this->actingAsUser($this->restrictedManager([$mine->id]));

        $this->assertCount(
            0,
            $this->getJson("/api/v1/email-templates?business_id={$theirs->id}")->assertOk()->json('data.data')
        );
    }

    public function test_a_global_manager_sees_every_application(): void
    {
        Business::factory()->count(3)->create();

        $this->actingAsUser($this->globalManager());

        $this->assertCount(3, $this->getJson('/api/v1/businesses')->assertOk()->json('data.data'));
    }
}
