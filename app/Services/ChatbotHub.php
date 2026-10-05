<?php

namespace App\Services;

use App\Jobs\SyncPortalConversationToChatbot;
use App\Models\Conversation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ChatbotHub
{
    public function queueInboundMessage(Conversation $conversation): void
    {
        if ($this->handles($conversation)) {
            $conversation->update(['chatbot_direction' => 'inbound']);
            SyncPortalConversationToChatbot::dispatch($conversation->id)->afterCommit();
        }
    }

    public function handles(Conversation $conversation): bool
    {
        return (bool) config('services.chatbot.enabled')
            && in_array($conversation->conversation_type, ['owner', 'tenant'], true);
    }

    /**
     * @param  string|list<string>|null  $mediaUrls
     */
    public function send(Conversation $conversation, string $body, string|array|null $mediaUrls = null): void
    {
        if (! $this->handles($conversation)) {
            throw new RuntimeException('This conversation is not enabled for chatbot delivery.');
        }

        Cache::lock('chatbot-send:'.$conversation->id, 120)->block(5, function () use ($conversation, $body, $mediaUrls): void {
            $conversation->refresh();
            $this->ensureAllowedInTestMode($conversation);
            $thread = $this->thread($conversation);
            $conversation->update([
                'chatbot_thread_id' => (string) $thread->hub_thread_id,
                'chatbot_direction' => $conversation->chatbot_direction ?: 'outbound',
            ]);
            $urls = array_values(array_filter((array) $mediaUrls));
            $parts = $urls === [] ? [null] : $urls;

            foreach ($parts as $index => $url) {
                $key = 'tx-maintenance:conversation:'.$conversation->id.':'.$index;
                $delivery = DB::table('chatbot_message_deliveries')->where('idempotency_key', $key)->first();

                if ($delivery?->hub_message_id !== null) {
                    continue;
                }

                DB::table('chatbot_message_deliveries')->insertOrIgnore([
                    'conversation_id' => $conversation->id,
                    'idempotency_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $partBody = $index === 0 ? $body : '';
                $media = $url ? $conversation->media->first(fn ($item) => $item->public_url === $url) : null;
                $mms = $media && in_array($media->content_type, ['image/jpeg', 'image/png', 'image/gif'], true)
                    && Storage::disk('local')->exists($media->local_path)
                    && Storage::disk('local')->size($media->local_path) < 4_800_000;

                if ($url && ! $mms) {
                    $partBody = trim($partBody."\n".$url);
                }

                $message = [
                    'from' => $conversation->chatbot_direction === 'inbound' ? 'client' : 'staff',
                    'body' => $partBody,
                    'media_url' => $mms ? $url : null,
                    'sender_name' => $conversation->chatbot_sender_name ?: 'Maintenance automation',
                    'sender_email' => $conversation->chatbot_sender_email,
                    'inbound_channel' => filled($conversation->twilio_sid) ? 'sms' : 'app',
                    'inbound_twilio_sid' => $conversation->chatbot_direction === 'inbound' && $index === 0 ? $conversation->twilio_sid : null,
                    'idempotency_key' => $key,
                ];
                $response = $this->client()->post('threads/'.$thread->hub_thread_id.'/messages', $message);

                // The support app refuses a staff email it has no account for;
                // the message still goes out, as the default staff account.
                $defaultSender = (string) config('services.chatbot.default_sender_email');
                if ($response->status() === 422 && $response->json('errors.sender_email') !== null
                    && $defaultSender !== '' && strcasecmp((string) $message['sender_email'], $defaultSender) !== 0) {
                    $response = $this->client()->post('threads/'.$thread->hub_thread_id.'/messages', ['sender_email' => $defaultSender] + $message);
                }

                $response = $response->throw()->json('data');

                if (! is_array($response) || empty($response['id'])) {
                    throw new RuntimeException('Chatbot did not return a message ID.');
                }

                DB::table('chatbot_message_deliveries')->where('idempotency_key', $key)->update([
                    'hub_message_id' => (string) $response['id'],
                    'updated_at' => now(),
                ]);

                DB::table('chatbot_message_deliveries')->where('idempotency_key', $key)->whereNull('event_at')->update([
                    'status' => $response['status'] ?? 'pending',
                    'twilio_sid' => $response['twilio_sid'] ?? null,
                    'error' => $response['error'] ?? null,
                ]);

            }

            app(ChatbotEventProcessor::class)->refreshStatus($conversation);
        });
    }

    /**
     * In test mode, refuse any number that is not allowlisted, before anything
     * reaches the support app. Throwing (rather than declining to handle) keeps
     * the message on the hub path, so it is marked failed and can never fall
     * back to Twilio.
     */
    private function ensureAllowedInTestMode(Conversation $conversation): void
    {
        if (! config('services.chatbot.test_mode')) {
            return;
        }

        $phone = $conversation->chatbot_direction === 'inbound' ? $conversation->sender_number : $conversation->receiver_number;
        $allowed = collect(explode(',', (string) config('services.chatbot.allowed_phones')))
            ->map(fn (string $number) => Conversation::lastTenDigits($number))
            ->filter();

        if (! $allowed->contains(Conversation::lastTenDigits($phone))) {
            throw new RuntimeException('Chatbot test mode: this number is not in CHATBOT_HUB_ALLOWED_PHONES, so it was not sent.');
        }
    }

    private function thread(Conversation $conversation): object
    {
        $inbound = $conversation->chatbot_direction === 'inbound';
        $phone = $inbound ? $conversation->sender_number : $conversation->receiver_number;
        $digits = Conversation::lastTenDigits($phone);

        if ($digits === null) {
            throw new RuntimeException('The recipient needs a valid phone number for chatbot messaging.');
        }

        $workOrder = $conversation->work_order()->withoutGlobalScopes()->firstOrFail();

        // A reply belongs in the thread already opened with this number on this
        // work order, whoever on it the number turns out to belong to.
        if ($inbound) {
            $existing = DB::table('chatbot_threads')
                ->where('work_order_id', $workOrder->id)
                ->where('party', $conversation->conversation_type)
                ->orderByDesc('id')
                ->get()
                ->first(fn (object $thread): bool => Conversation::lastTenDigits($thread->phone) === $digits);

            if ($existing !== null) {
                return $existing;
            }
        }

        $parties = $conversation->conversation_type === 'owner' ? $workOrder->owners : $workOrder->tenants;
        $matches = $parties->filter(function ($party) use ($phone, $conversation): bool {
            $numbers = $conversation->conversation_type === 'owner'
                ? [$party->mobile, $party->phone]
                : [$party->mobile_phone, $party->home_phone];

            return collect($numbers)->contains(fn ($number) => Conversation::lastTenDigits($number) === Conversation::lastTenDigits($phone));
        });
        if ($conversation->owner_id) {
            $matches = $matches->where('id', $conversation->owner_id);
        }
        // The same PropertyWare contact can sit on a work order twice (as the
        // requester and from the lease). Distinct people sharing one number
        // (co-owners, a household) get one thread for the number, not an error:
        // an error here would mean the text is never sent at all.
        $matches = $matches->unique(fn ($party): string => filled($party->propertyware_id) ? (string) $party->propertyware_id : 'local-'.$party->id);
        $contact = $matches->count() === 1 ? $matches->first() : null;

        $recipientKey = $contact?->propertyware_id ?: ($contact?->id ? 'local-'.$contact->id : $digits);
        $key = $conversation->work_order_id.':'.$conversation->conversation_type.':'.$recipientKey;

        return Cache::lock('chatbot-thread:'.$key, 40)->block(5, function () use ($conversation, $phone, $key, $workOrder, $contact): object {
            $existing = DB::table('chatbot_threads')->where('local_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            $response = $this->client()->post('threads', [
                'phone' => $phone,
                'contact_id' => $contact?->propertyware_id ? (string) $contact->propertyware_id : null,
                'work_order_id' => 'tx-maintenance:'.$workOrder->id,
                'work_order_number' => (string) $workOrder->work_order_no,
                'work_order_party' => $conversation->conversation_type,
                'channel' => 'Maintenance',
            ])->throw()->json('data');

            if (! is_array($response) || empty($response['id'])) {
                throw new RuntimeException('Chatbot did not return a thread ID.');
            }

            DB::table('chatbot_threads')->insert([
                'local_key' => $key,
                'hub_thread_id' => (string) $response['id'],
                'work_order_id' => $workOrder->id,
                'party' => $conversation->conversation_type,
                'phone' => $phone,
                'owner_id' => $conversation->conversation_type === 'owner' ? ($contact?->id ?? $conversation->owner_id) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('chatbot_threads')->where('local_key', $key)->first();
        });
    }

    private function client(): PendingRequest
    {
        $url = rtrim((string) config('services.chatbot.url'), '/');
        $token = (string) config('services.chatbot.token');

        if ($url === '' || $token === '') {
            throw new RuntimeException('Configure the chatbot hub URL and API token before enabling delivery.');
        }

        return Http::baseUrl($url.'/api/v1/')
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }
}
