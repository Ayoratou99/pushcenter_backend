<?php

namespace App\Mail;

use App\Models\SmtpSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmtpTestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $smtpSetting;

    /**
     * Create a new message instance.
     */
    public function __construct(SmtpSetting $smtpSetting)
    {
        $this->smtpSetting = $smtpSetting;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->smtpSetting->from_email,
                $this->smtpSetting->from_name
            ),
            subject: 'SMTP Configuration Test Email',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.smtp-test',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
