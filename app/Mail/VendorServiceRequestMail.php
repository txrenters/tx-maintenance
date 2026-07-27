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
        public bool $isVacant = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: 'workorders@texasrenters.com',
            subject: 'New Service Request - Work Order #'.$this->workOrderNo,
            cc: [
                'mc@texasrenters.com',
                'ofm@txhomemp.com',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vendor-service-request');
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
