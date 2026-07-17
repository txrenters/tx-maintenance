<?php

namespace App\Services;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;

class WorkOrderEmailSender
{
    /** @var array<int, string> */
    public const DEFAULT_CC = ['mc@texasrenters.com', 'ofm@txhomemp.com'];

    public function __construct(
        private MicrosoftGraphMailService $graph,
        private HtmlSanitizer $sanitizer,
        private AttachmentOptimizer $optimizer,
        private EmailAttachmentStore $attachments,
    ) {}

    /**
     * @param  array<int, UploadedFile|array{name: string, contentType: string, bytes: string}>  $files
     * @param  array<int, string>  $cc
     */
    public function sendVendorEmail(
        WorkOrder $workOrder,
        Vendor $vendor,
        string $subject,
        string $html,
        array $files = [],
        ?User $sentBy = null,
        array $cc = self::DEFAULT_CC,
        bool $trustedHtml = false,
    ): EmailMessage {
        $tag = 'TX-'.$workOrder->work_order_no.'-'.$vendor->id;
        $subject = str_contains($subject, '['.$tag.']')
            ? $subject
            : trim($subject).' ['.$tag.']';

        $finalHtml = $trustedHtml ? $html : $this->sanitizer->clean($html);

        // Optimize each file ONCE, then reuse the same bytes for both the Graph
        // attachment and the stored copy.
        $graphAttachments = [];
        $storable = [];
        foreach ($files as $file) {
            [$name, $mime, $bytes] = $this->normalizeFile($file);
            $bytes = $this->optimizer->optimize($bytes, $mime);
            $graphAttachments[] = [
                'name' => $name,
                'contentType' => $mime,
                'contentBytes' => base64_encode($bytes),
            ];
            $storable[] = [$name, $mime, $bytes];
        }

        $ids = $this->graph->sendMail($vendor->email, $cc, $subject, $finalHtml, $graphAttachments);

        $message = EmailMessage::create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'subject' => $subject,
            'body_html' => $finalHtml,
            'body_text' => trim(strip_tags($finalHtml)),
            'from_email' => config('services.microsoft.mailbox'),
            'to_email' => $vendor->email,
            'cc' => array_values($cc),
            'correlation_tag' => $tag,
            'graph_message_id' => $ids['graph_message_id'],
            'graph_conversation_id' => $ids['graph_conversation_id'],
            'internet_message_id' => $ids['internet_message_id'],
            'has_attachments' => $storable !== [],
            'sent_by_user_id' => $sentBy?->id,
            'emailed_at' => now(),
        ]);

        foreach ($storable as [$name, $mime, $bytes]) {
            // Bytes are already optimized above — persist stores them as-is.
            $this->attachments->persist($message, $name, $mime, $bytes);
        }

        return $message;
    }

    /**
     * @param  UploadedFile|array{name: string, contentType: string, bytes: string}  $file
     * @return array{0: string, 1: string, 2: string}
     */
    private function normalizeFile(UploadedFile|array $file): array
    {
        if ($file instanceof UploadedFile) {
            return [
                $file->getClientOriginalName(),
                $file->getMimeType() ?: 'application/octet-stream',
                (string) $file->get(),
            ];
        }

        return [$file['name'], $file['contentType'], $file['bytes']];
    }
}
