<?php

namespace Tests\Feature\Api;

use App\Jobs\SendSmtpTestEmail;
use App\Mail\SmtpTestEmail;
use App\Models\Business;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The "Send a test email" button of the email settings tab.
 */
class SmtpTestActionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private SmtpSetting $smtp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->smtp = SmtpSetting::factory()->forBusiness($this->business)->create();

        $this->actingAsUser($this->globalManager());
    }

    private function testUrl(?int $businessId = null, ?int $settingId = null): string
    {
        return sprintf(
            '/api/v1/businesses/%d/smtp-settings/%d/test',
            $businessId ?? $this->business->id,
            $settingId ?? $this->smtp->id
        );
    }

    public function test_the_button_queues_a_test_email(): void
    {
        Queue::fake();

        $this->postJson($this->testUrl(), ['recipient_email' => 'me@example.test'])
            ->assertOk()
            ->assertJsonPath('success', true);

        // The dedicated `emails` queue is the one Horizon watches.
        Queue::assertPushedOn('emails', SendSmtpTestEmail::class);
    }

    public function test_the_queued_job_carries_the_right_configuration(): void
    {
        Queue::fake();

        $this->postJson($this->testUrl(), ['recipient_email' => 'me@example.test'])->assertOk();

        Queue::assertPushed(SendSmtpTestEmail::class, function (SendSmtpTestEmail $job) {
            return $job->smtpSetting->id === $this->smtp->id
                && $job->recipientEmail === 'me@example.test';
        });
    }

    public function test_the_status_is_reset_while_the_test_runs(): void
    {
        Queue::fake();
        $this->smtp->update(['test_status' => 'failed', 'test_error' => 'previous failure']);

        $this->postJson($this->testUrl(), ['recipient_email' => 'me@example.test'])->assertOk();

        $this->smtp->refresh();
        $this->assertSame('not_tested', $this->smtp->test_status);
        $this->assertNull($this->smtp->test_error);
    }

    public function test_running_the_job_sends_the_email_and_records_success(): void
    {
        Mail::fake();

        (new SendSmtpTestEmail($this->smtp, 'me@example.test'))->handle();

        Mail::assertSent(SmtpTestEmail::class, fn (SmtpTestEmail $mail) => $mail->hasTo('me@example.test'));

        $this->smtp->refresh();
        $this->assertSame('success', $this->smtp->test_status);
        $this->assertNotNull($this->smtp->last_tested_at);
        $this->assertNull($this->smtp->test_error);
    }

    public function test_a_failing_smtp_is_recorded_on_the_setting(): void
    {
        // No Mail::fake here: the host does not resolve, so the send really fails.
        $this->smtp->update(['host' => 'smtp.invalid.invalid', 'timeout' => 1]);

        try {
            (new SendSmtpTestEmail($this->smtp->fresh(), 'me@example.test'))->handle();
            $this->fail('A broken SMTP host must make the job fail.');
        } catch (\Throwable) {
            // Expected: the job rethrows so the queue can retry.
        }

        $this->smtp->refresh();
        $this->assertSame('failed', $this->smtp->test_status);
        $this->assertNotNull($this->smtp->test_error);
        $this->assertNotNull($this->smtp->last_tested_at);
    }

    public function test_the_recipient_is_validated(): void
    {
        Queue::fake();

        $this->postJson($this->testUrl(), ['recipient_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['recipient_email']]);

        Queue::assertNothingPushed();
    }

    public function test_a_setting_of_another_application_is_not_testable(): void
    {
        Queue::fake();
        $other = Business::factory()->create();

        $this->postJson($this->testUrl($other->id, $this->smtp->id), ['recipient_email' => 'me@example.test'])
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_a_restricted_manager_cannot_test_another_application(): void
    {
        Queue::fake();
        $mine = Business::factory()->create();
        $this->actingAsUser($this->restrictedManager([$mine->id]));

        $this->postJson($this->testUrl(), ['recipient_email' => 'me@example.test'])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_the_action_requires_authentication(): void
    {
        Queue::fake();

        $this->withHeader('Authorization', 'Bearer invalid')
            ->postJson($this->testUrl(), ['recipient_email' => 'me@example.test'])
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }
}
