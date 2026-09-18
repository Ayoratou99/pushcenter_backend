<?php

namespace App\Mail;

use App\Models\EmailMessage;
use App\Models\SmtpSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An already rendered customer email, sent through the application's own SMTP
 * configuration. The body is stored on the EmailMessage, so nothing is
 * re-rendered here.
 */
class OutboundEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public EmailMessage $emailMessage,
        public SmtpSetting $smtpSetting,
    ) {
    }

    public function envelope(): Envelope
    {
        $replyTo = [];

        if ($this->smtpSetting->reply_to_email) {
            $replyTo[] = new Address(
                $this->smtpSetting->reply_to_email,
                $this->smtpSetting->reply_to_name ?: $this->smtpSetting->from_name
            );
        }

        return new Envelope(
            from: new Address(
                $this->emailMessage->sender_email ?: $this->smtpSetting->from_email,
                $this->emailMessage->sender_name ?: $this->smtpSetting->from_name
            ),
            replyTo: $replyTo,
            cc: $this->addresses($this->emailMessage->cc),
            bcc: $this->addresses($this->emailMessage->bcc),
            subject: (string) ($this->emailMessage->subject ?: '(no subject)'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: (string) $this->emailMessage->content,
        );
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function addresses($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        return array_values(array_filter((array) $value, fn ($address) => is_string($address) && $address !== ''));
    }
}
