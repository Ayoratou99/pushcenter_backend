<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendMessagesJob;
use App\Mail\OutboundEmail;
use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What actually happens once Horizon picks the job up.
 */
class SendMessagesJobTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private SmtpSetting $smtp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->smtp = SmtpSetting::factory()->forBusiness($this->business)->create([
            'from_email' => 'no-reply@shop.test',
            'from_name' => 'The Shop',
            'reply_to_email' => 'support@shop.test',
            'reply_to_name' => 'Shop Support',
        ]);
    }

    private function queuedEmail(array $overrides = []): Message
    {
        $message = Message::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'message_type' => 'email',
            'status' => 'queued',
            'sent_at' => null,
            'delivered_at' => null,
            'error_message' => null,
            'retry_count' => 0,
        ], $overrides));

        EmailMessage::create([
            'message_id' => $message->id,
            'is_template' => true,
            'recipient_email' => 'customer@example.test',
            'recipient_name' => 'Jane Customer',
            'subject' => 'Order A-1234 confirmed',
            'content' => '<p>Hello Jane, your order A-1234 is confirmed.</p>',
        ]);

        return $message;
    }

    public function test_the_job_goes_to_the_emails_queue(): void
    {
        $this->assertSame('emails', (new SendMessagesJob(1))->queue);
    }

    public function test_it_sends_the_email_and_marks_the_message_sent(): void
    {
        Mail::fake();
        $message = $this->queuedEmail();

        (new SendMessagesJob($message->id))->handle();

        Mail::assertSent(OutboundEmail::class, function (OutboundEmail $mail) {
            return $mail->hasTo('customer@example.test')
                && $mail->emailMessage->subject === 'Order A-1234 confirmed';
        });

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->error_message);
    }

    public function test_it_sends_through_the_application_own_smtp_settings(): void
    {
        Mail::fake();
        $message = $this->queuedEmail();

        (new SendMessagesJob($message->id))->handle();

        Mail::assertSent(OutboundEmail::class, function (OutboundEmail $mail) {
            $envelope = $mail->envelope();

            return $mail->smtpSetting->id === $this->smtp->id
                && $envelope->from->address === 'no-reply@shop.test'
                && $envelope->from->name === 'The Shop'
                && $envelope->replyTo[0]->address === 'support@shop.test';
        });
    }

    public function test_it_prefers_the_default_smtp_configuration(): void
    {
        Mail::fake();
        $secondary = SmtpSetting::factory()->forBusiness($this->business)->secondary()->create([
            'from_email' => 'other@shop.test',
        ]);

        (new SendMessagesJob($this->queuedEmail()->id))->handle();

        Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $mail) => $mail->smtpSetting->id === $this->smtp->id
            && $mail->smtpSetting->id !== $secondary->id);
    }

    public function test_it_falls_back_to_an_active_configuration_when_none_is_default(): void
    {
        Mail::fake();
        $this->smtp->update(['is_default' => false]);

        (new SendMessagesJob($this->queuedEmail()->id))->handle();

        Mail::assertSent(OutboundEmail::class);
    }

    public function test_it_never_uses_another_application_smtp(): void
    {
        Mail::fake();
        $otherBusiness = Business::factory()->create();
        SmtpSetting::factory()->forBusiness($otherBusiness)->create(['from_email' => 'leak@other.test']);

        (new SendMessagesJob($this->queuedEmail()->id))->handle();

        Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $mail) => $mail->smtpSetting->business_id === $this->business->id);
    }

    public function test_it_counts_the_message_against_the_smtp_configuration(): void
    {
        Mail::fake();

        (new SendMessagesJob($this->queuedEmail()->id))->handle();

        $this->smtp->refresh();
        $this->assertSame(1, $this->smtp->messages_sent);
        $this->assertNotNull($this->smtp->last_used_at);
    }

    public function test_it_fails_the_message_when_no_smtp_is_active(): void
    {
        Mail::fake();
        $this->smtp->update(['is_active' => false]);
        $message = $this->queuedEmail();

        try {
            (new SendMessagesJob($message->id))->handle();
            $this->fail('The job should have thrown so the queue can retry.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No active SMTP configuration', $e->getMessage());
        }

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertSame(1, $message->retry_count);
        Mail::assertNothingSent();
    }

    public function test_a_cancelled_message_is_not_sent(): void
    {
        Mail::fake();
        $message = $this->queuedEmail(['status' => 'cancelled']);

        (new SendMessagesJob($message->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame('cancelled', $message->fresh()->status);
    }

    public function test_a_deleted_message_is_ignored(): void
    {
        Mail::fake();

        (new SendMessagesJob(999999))->handle();

        Mail::assertNothingSent();
    }

    public function test_an_unimplemented_channel_fails_loudly(): void
    {
        Mail::fake();
        $message = Message::factory()->create([
            'business_id' => $this->business->id,
            'message_type' => 'sms',
            'status' => 'queued',
        ]);

        try {
            (new SendMessagesJob($message->id))->handle();
            $this->fail('An unimplemented channel must not report success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not implemented', $e->getMessage());
        }

        // The old stub reported "sent" for every channel; it must not any more.
        $this->assertSame('failed', $message->fresh()->status);
    }

    public function test_a_permanent_failure_is_recorded(): void
    {
        $message = $this->queuedEmail();

        (new SendMessagesJob($message->id))->failed(new \RuntimeException('SMTP refused the connection'));

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('SMTP refused the connection', $message->error_message);
    }
}
