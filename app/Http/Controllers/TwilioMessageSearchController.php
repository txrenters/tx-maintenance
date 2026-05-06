<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
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

                $messages = $this->attachLocalMatches($messages);
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

        if ($updatedRecords === 0) {
            return back()->with('warning', 'Twilio status: '.$message['status'].'. No local record matched SID '.$message['sid'].'.');
        }

        return back()->with('success', 'Synced Twilio status ('.$message['status'].') to '.$updatedRecords.' local record(s).');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function attachLocalMatches(array $messages): array
    {
        $sids = collect($messages)
            ->pluck('sid')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($sids)) {
            return $messages;
        }

        $conversationMatches = Conversation::query()
            ->whereIn('twilio_sid', $sids)
            ->get(['twilio_sid', 'work_order_id', 'twilio_status'])
            ->keyBy('twilio_sid');

        $jobberMatches = collect();
        if ($this->jobberTextMessagesHasTwilioColumns()) {
            $jobberMatches = JobberTextMessage::query()
                ->whereIn('twilio_sid', $sids)
                ->get(['twilio_sid', 'jobber_id', 'jobber_visit_id', 'twilio_status'])
                ->keyBy('twilio_sid');
        }

        return array_map(function (array $message) use ($conversationMatches, $jobberMatches): array {
            $sid = $message['sid'] ?? null;
            $message['local_match'] = null;
            $message['local_status_diff'] = false;

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
