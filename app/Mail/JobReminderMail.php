<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $tenantName,
        public string $visitDate,
        public string $body,
        public string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: 'service@txhomemp.com',
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.job-reminder');
    }
}
