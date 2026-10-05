<?php

namespace App\Services;

use App\Jobs\ImportChatbotMedia;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChatbotEventProcessor
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function process(array $event): void
    {
        if (! in_array($event['event'], ['message.received', 'message.sent', 'message.status', 'message.moved'], true)) {
            return;
        }

        $data = $event['data'];
        $message = $data['message'] ?? [];
        abort_unless(isset($message['id'], $message['direction']), 422);

        DB::transaction(function () use ($event, $data, $message): void {
            if (! DB::table('chatbot_events')->insertOrIgnore(['id' => $event['id'], 'created_at' => now()])) {
                return;
            }

            $thread = DB::table('chatbot_threads')->where('hub_thread_id', (string) $data['thread']['id'])->lockForUpdate()->first();
            if (! $thread && ! str_starts_with((string) ($data['thread']['work_order_id'] ?? ''), 'tx-maintenance:')) {
                return;
            }
            abort_unless($thread, 409, 'The maintenance thread has not been linked yet.');

            $delivery = DB::table('chatbot_message_deliveries')
                ->where('hub_message_id', (string) $message['id'])
                ->when(filled($message['idempotency_key'] ?? null), fn ($query) => $query->orWhere('idempotency_key', $message['idempotency_key']))
                ->lockForUpdate()->first();
            $at = CarbonImmutable::parse($event['occurred_at']);

            if ($delivery?->event_at && CarbonImmutable::parse($delivery->event_at)->greaterThan($at)) {
                return;
            }
            if ($delivery && in_array($delivery->status, ['delivered', 'failed', 'undelivered'], true)
                && in_array($message['status'] ?? null, ['pending', 'queued', 'sending', 'sent'], true)) {
                return;
            }

            $inbound = $message['direction'] === 'inbound';
            $conversation = $delivery
                ? Conversation::withoutGlobalScopes()->findOrFail($delivery->conversation_id)
                : new Conversation;
            $new = ! $conversation->exists;

            $conversation->fill([
                'work_order_id' => $thread->work_order_id,
                'conversation_type' => $thread->party,
                'owner_id' => $thread->owner_id,
                'chatbot_thread_id' => $thread->hub_thread_id,
                'chatbot_direction' => $message['direction'],
                'chatbot_sender_name' => $message['sender_name'] ?? $conversation->chatbot_sender_name,
                'chatbot_sender_email' => $message['sender_email'] ?? $conversation->chatbot_sender_email,
            ]);

            if ($new) {
                $conversation->fill([
                    'message' => (string) ($message['body'] ?? ''),
                    'sender_number' => $inbound ? $thread->phone : config('services.chatbot.phone'),
                    'receiver_number' => $inbound ? config('services.chatbot.phone') : $thread->phone,
                    'is_read' => ! $inbound,
                    'read_by_owner' => $inbound,
                    'read_by_tenant' => $inbound,
                    'created_at' => $message['created_at'] ?? $at,
                ]);
            }

            $conversation->save();
            DB::table('chatbot_message_deliveries')->updateOrInsert(
                $delivery ? ['id' => $delivery->id] : ['hub_message_id' => (string) $message['id']],
                [
                    'conversation_id' => $conversation->id,
                    'hub_message_id' => (string) $message['id'],
                    'status' => $message['status'] ?? null,
                    'twilio_sid' => $message['twilio_sid'] ?? null,
                    'error' => $message['error'] ?? null,
                    'event_at' => $at,
                    'updated_at' => now(),
                    'created_at' => $delivery?->created_at ?? now(),
                ]
            );
            $this->refreshStatus($conversation);

            if ($new && ! empty($message['media_urls'])) {
                ImportChatbotMedia::dispatch($conversation->id, $message['media_urls'])->afterCommit();
            }

            if ($new && $inbound) {
                activity()->performedOn($conversation)->event('work_order_message_received')
                    ->withProperties(['senderNumber' => $thread->phone, 'receiverNumber' => config('services.chatbot.phone'), 'message' => $conversation->message, 'work_order_id' => $thread->work_order_id])
                    ->log('Work Order - New Message Received');
            }
        });
    }

    public function refreshStatus(Conversation $conversation): void
    {
        $deliveries = DB::table('chatbot_message_deliveries')->where('conversation_id', $conversation->id)->get();
        $failed = $deliveries->first(fn ($delivery) => in_array($delivery->status, ['failed', 'undelivered'], true));
        $status = $failed?->status;

        if (! $status) {
            $status = $deliveries->every(fn ($delivery) => $delivery->status === 'delivered')
                ? 'delivered'
                : ($deliveries->first(fn ($delivery) => $delivery->status !== 'delivered')?->status ?? 'pending');
        }

        $conversation->update([
            'twilio_status' => $conversation->chatbot_direction === 'inbound' ? null : $status,
            'twilio_sid' => $deliveries->first()?->twilio_sid ?? $conversation->twilio_sid,
            'twilio_error_message' => $failed?->error,
            'twilio_status_updated_at' => now(),
        ]);
    }
}
