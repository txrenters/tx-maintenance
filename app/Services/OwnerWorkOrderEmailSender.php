<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class OwnerWorkOrderEmailSender
{
    public const DEFAULT_CC = ['mc@texasrenters.com', 'ofm@txhomemp.com'];

    public function __construct(
        private MicrosoftGraphMailService $graph,
        private HtmlSanitizer $sanitizer,
        private AttachmentOptimizer $optimizer,
        private OwnerEmailAttachmentStore $attachmentStore,
    ) {}

    /**
     * @param  array<int, array{name: string, contentType: string, bytes: string}>  $files
     * @param  array<string, mixed>  $metadata
     */
    public function send(
        Owner $owner,
        ?WorkOrder $workOrder,
        string $to,
        string $mailbox,
        string $subject,
        string $html,
        array $files = [],
        ?User $sentBy = null,
        bool $trustedHtml = false,
        array $metadata = [],
    ): OwnerEmailNotification {
        $tag = $workOrder
            ? 'TXO-'.$workOrder->work_order_no.'-'.$owner->id
            : 'TXO-GEN-'.$owner->id;
        $taggedSubject = str_contains($subject, '['.$tag.']') ? $subject : trim($subject).' ['.$tag.']';
        $finalHtml = $trustedHtml ? $html : $this->sanitizer->clean($html);
        $graphAttachments = [];
        $storable = [];

        foreach ($files as $file) {
            $bytes = $this->optimizer->optimize($file['bytes'], $file['contentType']);
            $graphAttachments[] = ['name' => $file['name'], 'contentType' => $file['contentType'], 'contentBytes' => base64_encode($bytes)];
            $storable[] = [$file['name'], $file['contentType'], $bytes];
        }

        $result = $this->graph->sendMail($to, [], $taggedSubject, $finalHtml, $graphAttachments, $mailbox);

        return DB::transaction(function () use ($owner, $workOrder, $to, $taggedSubject, $finalHtml, $mailbox, $result, $storable, $sentBy, $metadata, $tag): OwnerEmailNotification {
            $notification = OwnerEmailNotification::query()->create([
                'work_order_id' => $workOrder?->id,
                'owner_id' => $owner->id,
                'vendor_id' => null,
                'type' => 'manual',
                'direction' => 'outbound',
                'subject' => $taggedSubject,
                'body_html' => $finalHtml,
                'body_text' => trim(strip_tags($finalHtml)),
                'from_email' => $mailbox,
                'to_email' => $to,
                'cc' => [],
                'correlation_tag' => $tag,
                'graph_message_id' => $result['graph_message_id'],
                'graph_conversation_id' => $result['graph_conversation_id'],
                'internet_message_id' => $result['internet_message_id'],
                'has_attachments' => $storable !== [],
                'sent_by_user_id' => $sentBy?->id,
                'metadata' => $metadata,
                'sent_at' => now(),
            ]);

            foreach ($storable as [$name, $mime, $bytes]) {
                $this->attachmentStore->persist($notification, $name, $mime, $bytes);
            }

            return $notification;
        });
    }

    /**
     * @param  array<int, string>  $extraCc  Additional CC recipients (e.g. co-owners), merged with DEFAULT_CC.
     * @param  array<string, mixed>  $metadata
     */
    public function sendVendorAssignment(WorkOrder $workOrder, Owner $owner, Vendor $vendor, string $subject, string $html, array $extraCc = [], array $metadata = []): OwnerEmailNotification
    {
        $mailbox = (string) config('services.microsoft.mailbox');
        $tag = 'TXO-'.$workOrder->work_order_no.'-'.$owner->id;
        $taggedSubject = str_contains($subject, '['.$tag.']') ? $subject : $subject.' ['.$tag.']';
        $idempotencyKey = 'vendor_assignment:'.$workOrder->id.':'.$vendor->id.':'.$owner->id;

        $cc = array_values(array_filter(
            array_unique(array_merge(self::DEFAULT_CC, $extraCc)),
            fn (string $email): bool => strcasecmp($email, (string) $owner->email) !== 0,
        ));

        $notification = OwnerEmailNotification::query()->createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            ['work_order_id' => $workOrder->id, 'vendor_id' => $vendor->id, 'owner_id' => $owner->id, 'type' => 'vendor_assignment', 'direction' => 'outbound', 'subject' => $taggedSubject, 'body_html' => $html, 'body_text' => trim(strip_tags($html)), 'from_email' => $mailbox, 'to_email' => $owner->email, 'cc' => $cc, 'correlation_tag' => $tag, 'metadata' => $metadata],
        );

        return DB::transaction(function () use ($notification, $owner, $taggedSubject, $html, $mailbox, $cc): OwnerEmailNotification {
            $locked = OwnerEmailNotification::query()->lockForUpdate()->findOrFail($notification->id);

            if ($locked->graph_message_id !== null) {
                return $locked;
            }

            $result = $this->graph->sendMail($owner->email, $cc, $taggedSubject, $html, mailbox: $mailbox);
            $locked->update([
                'graph_message_id' => $result['graph_message_id'],
                'graph_conversation_id' => $result['graph_conversation_id'],
                'internet_message_id' => $result['internet_message_id'],
                'sent_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
