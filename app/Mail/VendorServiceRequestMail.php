<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorServiceRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $pdfContent  Raw PDF bytes for the work order service request.
     */
    public function __construct(
        public string $vendorName,
        public string $workOrderNo,
        public string $pdfContent,
        public ?string $portalUrl = null,
        public string $pdfFileName = 'Work Order Information.pdf',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Service Request - Work Order #'.$this->workOrderNo,
            cc: ['woc@texasrenters.com'],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.vendor-service-request');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFileName)
                ->withMime('application/pdf'),
        ];
    }
}
