<?php

namespace App\Services;

use App\Models\Jobber;
use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantJobberEmailSender
{
    public function __construct(
        private MicrosoftGraphMailService $graph,
        private HtmlSanitizer $sanitizer,
        private AttachmentOptimizer $optimizer,
        private TenantEmailAttachmentStore $attachmentStore,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array{name: string, contentType: string, bytes: string}>  $files
     */
    public function send(Tenants $tenant, ?Jobber $job, string $to, string $subject, string $html, array $metadata = [], array $files = [], ?User $sentBy = null, bool $trustedHtml = false, ?string $mailbox = null): TenantEmailNotification
    {
        $mailbox ??= (string) config('services.microsoft.job_reminder_mailbox');
        $correlationTag = $job
            ? 'TBP-'.$job->id.'-'.$tenant->id
            : 'TNT-'.$tenant->id;
        $taggedSubject = str_contains($subject, '['.$correlationTag.']') ? $subject : $subject.' ['.$correlationTag.']';
        $finalHtml = $trustedHtml ? $html : $this->sanitizer->clean($html);
        $finalFiles = array_map(function (array $file): array {
            $file['bytes'] = $this->optimizer->optimize($file['bytes'], $file['contentType']);

            return $file;
        }, $files);
        $graphFiles = array_map(fn (array $file): array => [
            'name' => $file['name'],
            'contentType' => $file['contentType'],
            'contentBytes' => base64_encode($file['bytes']),
        ], $finalFiles);
        $result = $this->graph->sendMail($to, [], $taggedSubject, $finalHtml, $graphFiles, $mailbox);

        return DB::transaction(function () use ($tenant, $job, $to, $taggedSubject, $finalHtml, $mailbox, $correlationTag, $result, $finalFiles, $sentBy, $metadata): TenantEmailNotification {
            $notification = TenantEmailNotification::query()->create([
                'tenant_id' => $tenant->id,
                'jobber_job_id' => $job?->id,
                'type' => $job ? 'job_reminder' : 'manual',
                'direction' => 'outbound',
                'subject' => $taggedSubject,
                'body_html' => $finalHtml,
                'body_text' => trim(strip_tags($finalHtml)),
                'from_email' => $mailbox,
                'to_email' => $to,
                'correlation_tag' => $correlationTag,
                'graph_message_id' => $result['graph_message_id'],
                'graph_conversation_id' => $result['graph_conversation_id'],
                'internet_message_id' => $result['internet_message_id'],
                'has_attachments' => $finalFiles !== [],
                'sent_by_user_id' => $sentBy?->id,
                'metadata' => $metadata,
                'sent_at' => now(),
            ]);

            foreach ($finalFiles as $file) {
                $this->attachmentStore->persist($notification, $file['name'], $file['contentType'], $file['bytes']);
            }

            if ($job) {
                $tenant->jobberJobs()->syncWithoutDetaching([$job->id]);
            }

            return $notification;
        });
    }
}
