<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobberVendorAssignmentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $vendorName,
        public string $jobNumber,
        public string $title,
        public ?string $clientName = null,
        public ?string $clientPhone = null,
        public ?string $propertyAddress = null,
        public ?string $instructions = null,
        public ?string $startAt = null,
        public ?string $portalUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Job Assignment - Job #'.$this->jobNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.jobber-vendor-assignment',
        );
    }
}
