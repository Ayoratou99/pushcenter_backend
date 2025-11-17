<?php

namespace App\Jobs;

use App\Mail\SmtpTestEmail;
use App\Models\SmtpSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SendSmtpTestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $smtpSetting;
    public $recipientEmail;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(SmtpSetting $smtpSetting, string $recipientEmail)
    {
        $this->smtpSetting = $smtpSetting;
        $this->recipientEmail = $recipientEmail;
        // Dispatch to emails queue
        $this->onQueue('emails');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Configure mailer dynamically based on SMTP setting
            Config::set('mail.mailers.smtp', [
                'transport' => 'smtp',
                'host' => $this->smtpSetting->host,
                'port' => $this->smtpSetting->port,
                'encryption' => $this->smtpSetting->encryption === 'none' ? null : $this->smtpSetting->encryption,
                'username' => $this->smtpSetting->username,
                'password' => $this->smtpSetting->password,
                'timeout' => $this->smtpSetting->timeout ?? 30,
                'verify_peer' => $this->smtpSetting->verify_peer ?? true,
            ]);

            // Send test email
            Mail::mailer('smtp')
                ->to($this->recipientEmail)
                ->send(new SmtpTestEmail($this->smtpSetting));

            // Update test status to success
            $this->smtpSetting->update([
                'test_status' => 'success',
                'last_tested_at' => now(),
                'test_error' => null,
            ]);
        } catch (\Exception $e) {
            // Update test status with error
            $this->smtpSetting->update([
                'test_status' => 'failed',
                'last_tested_at' => now(),
                'test_error' => $e->getMessage(),
            ]);

            // Re-throw to trigger job failure handling
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Update test status with error
        $this->smtpSetting->update([
            'test_status' => 'failed',
            'last_tested_at' => now(),
            'test_error' => $exception->getMessage(),
        ]);
    }
}
