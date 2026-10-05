<?php

namespace App\Http\Controllers;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendJobberTextMessageJob;
use App\Models\Conversation;
use App\Models\JobberTextMessage;
use App\Services\ChatbotHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ResendTwilioMessageController extends Controller
{
    public function conversation(Conversation $conversation): JsonResponse
    {
        $usesHub = app(ChatbotHub::class)->handles($conversation);
        if ($usesHub) {
            abort_unless(auth()->user()?->hasAnyRole(['admin', 'woc', 'accounting']), 403);
        }
        if (! $this->isFailureStatus($conversation->twilio_status)) {
            return response()->json([
                'error' => 'Only failed or undelivered messages can be resent.',
            ], 422);
        }

        $body = (string) ($conversation->message ?? '');
        $to = (string) $conversation->receiver_number;
        $from = (string) $conversation->sender_number;
        $firstMediaUrl = $conversation->media()->first()?->public_url;

        $new = Conversation::create([
            'message' => $body,
            'is_mms' => (bool) $conversation->is_mms,
            'conversation_type' => $conversation->conversation_type,
            'sender_number' => $from,
            'receiver_number' => $to,
            'work_order_id' => $conversation->work_order_id,
            'is_read' => true,
        ]);

        if ($usesHub) {
            $new->update([
                'owner_id' => $conversation->owner_id,
                'chatbot_direction' => 'outbound',
                'chatbot_sender_name' => auth()->user()->name,
                'chatbot_sender_email' => auth()->user()->email,
            ]);
        }

        SendConversationMessageJob::dispatch($to, $from, $body, $firstMediaUrl, $new->id);

        Log::info('Work-order conversation resend queued', [
            'original_id' => $conversation->id,
            'new_id' => $new->id,
            'to' => $to,
            'from' => $from,
            'has_media' => (bool) $firstMediaUrl,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message queued for redelivery.',
            'new_conversation_id' => $new->id,
        ]);
    }

    public function jobberTextMessage(JobberTextMessage $jobberTextMessage): JsonResponse
    {
        if (! $this->isFailureStatus($jobberTextMessage->twilio_status)) {
            return response()->json([
                'error' => 'Only failed or undelivered messages can be resent.',
            ], 422);
        }

        $body = (string) ($jobberTextMessage->messages ?? '');
        $to = (string) $jobberTextMessage->receiver_number;
        $from = (string) $jobberTextMessage->sender_number;
        $mediaUrl = $jobberTextMessage->image ?: null;

        $new = JobberTextMessage::create([
            'messages' => $body,
            'sender_number' => $from,
            'receiver_number' => $to,
            'jobber_id' => $jobberTextMessage->jobber_id,
            'image' => $mediaUrl,
        ] + (JobberTextMessage::hasVisitColumn()
            ? ['jobber_visit_id' => $jobberTextMessage->jobber_visit_id]
            : []));

        SendJobberTextMessageJob::dispatch($new, $body, $mediaUrl);

        Log::info('Jobber message resend queued', [
            'original_id' => $jobberTextMessage->id,
            'new_id' => $new->id,
            'to' => $to,
            'from' => $from,
            'has_media' => (bool) $mediaUrl,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message queued for redelivery.',
            'new_jobber_text_message_id' => $new->id,
        ]);
    }

    private function isFailureStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['failed', 'undelivered'], true);
    }
}
