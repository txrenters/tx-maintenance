<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
use App\Models\Scopes\ConversationScope;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Response;

class TwilioMessageSearchController extends Controller
{
    public function __construct(protected TwilioService $twilio) {}

    public function index(Request $request): Response
    {
        Gate::authorize('search_twilio_messages');

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $filters = [
            'phone' => trim((string) ($validated['phone'] ?? '')),
            'date_from' => (string) ($validated['date_from'] ?? ''),
            'date_to' => (string) ($validated['date_to'] ?? ''),
        ];

        $hasFilters = $filters['phone'] !== ''
            || $filters['date_from'] !== ''
            || $filters['date_to'] !== '';

        $messages = [];
        $error = null;
        $resultLimit = 100;
        $ourTwilioNumbers = $this->ourTwilioNumbers();

        if ($hasFilters) {
            try {
                $messages = $this->twilio->searchMessages([
                    'phone' => $this->normalizePhone($filters['phone']),
                    'date_from' => $filters['date_from'] !== ''
                        ? Carbon::parse($filters['date_from'])->startOfDay()
                        : null,
                    'date_to' => $filters['date_to'] !== ''
                        ? Carbon::parse($filters['date_to'])->endOfDay()
                        : null,
                    'limit' => $resultLimit,
                ]);

                $messages = $this->attachLocalMatches($messages, $ourTwilioNumbers);
            } catch (\Throwable $e) {
                Log::error('Twilio message search failed', [
                    'error' => $e->getMessage(),
                    'filters' => $filters,
                ]);
                $error = 'Twilio API error: '.$e->getMessage();
            }
        }

        return inertia('TwilioMessageSearch', [
            'title' => 'Search Twilio',
            'filters' => $filters,
            'messages' => $messages,
            'error' => $error,
            'hasFilters' => $hasFilters,
            'resultLimit' => $resultLimit,
            'ourTwilioNumbers' => $ourTwilioNumbers,
        ]);
    }

    public function syncStatus(Request $request): RedirectResponse
    {
        Gate::authorize('search_twilio_messages');

        $validated = $request->validate([
            'sid' => ['required', 'string', 'max:64'],
        ]);

        $sid = trim($validated['sid']);

        try {
            $message = $this->twilio->fetchMessage($sid);
        } catch (\Throwable $e) {
            Log::error('Twilio status sync failed', [
                'sid' => $sid,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Twilio API error: '.$e->getMessage());
        }

        if ($message === null) {
            return back()->with('error', 'No Twilio message found for SID '.$sid.'.');
        }

        $payload = [
            'twilio_status' => $message['status'],
            'twilio_error_code' => $message['error_code'],
            'twilio_error_message' => $message['error_message'],
            'twilio_status_updated_at' => now(),
        ];

        $updatedRecords = 0;
        $updatedRecords += Conversation::query()
            ->where('twilio_sid', $message['sid'])
            ->update($payload);

        if ($this->jobberTextMessagesHasTwilioColumns()) {
            $updatedRecords += JobberTextMessage::query()
                ->where('twilio_sid', $message['sid'])
                ->update($payload);
        }

        if ($updatedRecords > 0) {
            return back()->with('success', 'Synced Twilio status ('.$message['status'].') to '.$updatedRecords.' local record(s).');
        }

        if ($this->isImportable($message)) {
            return $this->importInboundMessage($message);
        }

        return back()->with('warning', 'Twilio status: '.$message['status'].'. No local record matched SID '.$message['sid'].'.');
    }

    /**
     * Create a Conversation row for an inbound Twilio message that is missing locally.
     *
     * @param  array<string, mixed>  $message
     */
    protected function importInboundMessage(array $message): RedirectResponse
    {
        $customerPhone = (string) ($message['from'] ?? '');
        $ourNumber = (string) ($message['to'] ?? '');

        $thread = $this->findMostRecentThreadForPhone($customerPhone);

        if ($thread === null) {
            return back()->with('warning', 'No work order conversation found for '.$customerPhone.'. Cannot import — please reply to this customer from a work order first.');
        }

        try {
            $conversation = Conversation::create([
                'message' => (string) ($message['body'] ?? ''),
                'is_mms' => (int) ($message['num_media'] ?? 0) > 0,
                'conversation_type' => $thread->conversation_type,
                'sender_number' => $customerPhone,
                'receiver_number' => $ourNumber,
                'work_order_id' => $thread->work_order_id,
                'twilio_sid' => $message['sid'] ?? null,
                'twilio_status' => $message['status'] ?? null,
                'twilio_error_code' => $message['error_code'] ?? null,
                'twilio_error_message' => $message['error_message'] ?? null,
                'twilio_status_updated_at' => now(),
                'created_at' => $this->parseTwilioDate($message['date_sent'] ?? $message['date_created'] ?? null),
                'updated_at' => $this->parseTwilioDate($message['date_updated'] ?? $message['date_sent'] ?? null),
            ]);

            Log::info('Twilio message imported via search page', [
                'sid' => $message['sid'] ?? null,
                'conversation_id' => $conversation->id,
                'work_order_id' => $thread->work_order_id,
            ]);

            $workOrder = WorkOrder::find($thread->work_order_id);
            $resolvedWorkOrderNo = $workOrder?->work_order_no ?? $thread->work_order_id;

            activity()
                ->performedOn($conversation)
                ->event('work_order_message_received')
                ->withProperties([
                    'senderNumber' => $customerPhone,
                    'receiverNumber' => $ourNumber,
                    'message' => (string) ($message['body'] ?? ''),
                    'work_order_id' => $thread->work_order_id,
                    'imported_via' => 'twilio_search',
                ])
                ->log('Work Order #'.$resolvedWorkOrderNo.' - New Message Received');
        } catch (\Throwable $e) {
            Log::error('Twilio message import failed', [
                'sid' => $message['sid'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to import message: '.$e->getMessage());
        }

        return back()->with('success', 'Imported inbound message into work order #'.$thread->work_order_id.'.');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function isImportable(array $message): bool
    {
        $direction = strtolower((string) ($message['direction'] ?? ''));
        if (! str_starts_with($direction, 'inbound')) {
            return false;
        }

        $to = $this->normalizePhone((string) ($message['to'] ?? ''));

        return $to !== null && in_array($to, $this->ourTwilioNumbers(), true);
    }

    protected function findMostRecentThreadForPhone(string $phone): ?Conversation
    {
        $candidates = $this->phoneVariants($phone);
        if (empty($candidates)) {
            return null;
        }

        return Conversation::query()
            ->withoutGlobalScope(ConversationScope::class)
            ->where(function ($query) use ($candidates) {
                $query->whereIn('sender_number', $candidates)
                    ->orWhereIn('receiver_number', $candidates);
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    protected function phoneVariants(string $phone): array
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            return [];
        }

        $digits = preg_replace('/\D/', '', $normalized) ?? '';
        $variants = [$normalized, $digits];

        if (str_starts_with($digits, '1') && strlen($digits) === 11) {
            $variants[] = substr($digits, 1);
        }

        return array_values(array_filter(array_unique($variants), fn ($v) => $v !== ''));
    }

    /**
     * @return array<int, string>
     */
    protected function ourTwilioNumbers(): array
    {
        $configured = [
            (string) config('services.twilio.phone_number', env('TWILIO_PHONE_NUMBER')),
            (string) env('MAINTENANC_TWILIO_PHONE_NUMBER', ''),
        ];

        $normalized = [];
        foreach ($configured as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $normalizedValue = $this->normalizePhone($value);
            if ($normalizedValue !== null) {
                $normalized[] = $normalizedValue;
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function parseTwilioDate(?string $value): \DateTimeInterface
    {
        if (! $value) {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, string>  $ourTwilioNumbers
     * @return array<int, array<string, mixed>>
     */
    protected function attachLocalMatches(array $messages, array $ourTwilioNumbers = []): array
    {
        $sids = collect($messages)
            ->pluck('sid')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $conversationMatches = collect();
        $jobberMatches = collect();

        if (! empty($sids)) {
            $conversationMatches = Conversation::query()
                ->whereIn('twilio_sid', $sids)
                ->get(['twilio_sid', 'work_order_id', 'twilio_status'])
                ->keyBy('twilio_sid');

            if ($this->jobberTextMessagesHasTwilioColumns()) {
                $jobberMatches = JobberTextMessage::query()
                    ->whereIn('twilio_sid', $sids)
                    ->get(['twilio_sid', 'jobber_id', 'jobber_visit_id', 'twilio_status'])
                    ->keyBy('twilio_sid');
            }
        }

        return array_map(function (array $message) use ($conversationMatches, $jobberMatches, $ourTwilioNumbers): array {
            $sid = $message['sid'] ?? null;
            $message['local_match'] = null;
            $message['local_status_diff'] = false;
            $message['importable'] = false;

            if ($sid !== null && $conversationMatches->has($sid)) {
                $row = $conversationMatches->get($sid);
                $message['local_match'] = [
                    'source' => 'work_order',
                    'work_order_id' => $row->work_order_id,
                    'local_status' => $row->twilio_status,
                ];
                $message['local_status_diff'] = $this->statusDiffers($message['status'] ?? null, $row->twilio_status);
            } elseif ($sid !== null && $jobberMatches->has($sid)) {
                $row = $jobberMatches->get($sid);
                $message['local_match'] = [
                    'source' => 'job',
                    'jobber_id' => $row->jobber_id,
                    'jobber_visit_id' => $row->jobber_visit_id ?? null,
                    'local_status' => $row->twilio_status,
                ];
                $message['local_status_diff'] = $this->statusDiffers($message['status'] ?? null, $row->twilio_status);
            } else {
                $direction = strtolower((string) ($message['direction'] ?? ''));
                $toNormalized = $this->normalizePhone((string) ($message['to'] ?? ''));
                if (
                    str_starts_with($direction, 'inbound')
                    && $toNormalized !== null
                    && in_array($toNormalized, $ourTwilioNumbers, true)
                ) {
                    $message['importable'] = true;
                }
            }

            return $message;
        }, $messages);
    }

    protected function statusDiffers(?string $remote, ?string $local): bool
    {
        $remote = strtolower(trim((string) $remote));
        $local = strtolower(trim((string) $local));

        if ($remote === '' || $local === '') {
            return false;
        }

        return $remote !== $local;
    }

    protected function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            $digits = '+'.preg_replace('/\D/', '', $phone);

            return $digits === '+' ? null : $digits;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    protected function jobberTextMessagesHasTwilioColumns(): bool
    {
        return Schema::hasColumn('jobber_text_messages', 'twilio_sid')
            && Schema::hasColumn('jobber_text_messages', 'twilio_status');
    }
}
