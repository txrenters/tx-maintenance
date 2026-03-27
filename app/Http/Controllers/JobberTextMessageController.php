<?php

namespace App\Http\Controllers;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendJobberTextMessageJob;
use App\Models\JobberTextMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class JobberTextMessageController extends Controller
{
    public function index(Request $request)
    {
        $query = JobberTextMessage::with('jobber');

        // Filter by jobber_id if provided
        if ($request->has('jobber_id')) {
            $query->where('jobber_id', $request->jobber_id);
        }

        $messages = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($messages);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'messages' => 'nullable|string|max:1600',
            'sender_number' => 'required|string',
            'receiver_numbers' => 'required|array|min:1',
            'receiver_numbers.*' => 'required|string',
            'jobber_id' => 'required|exists:jobber_jobs,id',
            'jobber_visit_id' => 'nullable|exists:jobber_visits,id',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120', // 5MB max per image
        ]);

        // Validate that either message or image is provided
        if (empty($validatedData['messages']) && ! $request->hasFile('images')) {
            return response()->json(['error' => 'Please provide either a message or an image.'], 422);
        }

        // Format phone numbers ensuring proper + prefix
        $senderNumber = $validatedData['sender_number'];
        $receiverNumbers = array_map([$this, 'formatNumber'], $validatedData['receiver_numbers']);

        try {
            $hasVisitColumn = JobberTextMessage::hasVisitColumn();
            $visitId = $hasVisitColumn ? ($validatedData['jobber_visit_id'] ?? null) : null;
            $messageColumns = $this->getMessageColumnAvailability();

            // Store all uploaded images upfront and collect public URLs
            $imagePaths = [];
            $mediaUrls = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $filename = time().'_'.$image->getClientOriginalName();
                    $imagePath = $image->storeAs('jobber_images', $filename, 'public');
                    $imagePaths[] = $imagePath;
                    $mediaUrls[] = asset('storage/'.$imagePath);
                }
            }

            $firstImagePath = $imagePaths[0] ?? null;
            $firstMediaUrl = $mediaUrls[0] ?? null;
            $extraMediaUrls = array_slice($mediaUrls, 1);

            $messageContent = $validatedData['messages'] ?? '';
            $savedMessages = [];
            $failedRecipients = [];

            // Create records and dispatch jobs — one per recipient
            foreach ($receiverNumbers as $receiverNumber) {
                // Check for duplicate messages sent within the last 1 minute to prevent double-submission
                $recentMessageQuery = JobberTextMessage::where('receiver_number', $receiverNumber)
                    ->where('jobber_id', $validatedData['jobber_id'])
                    ->where('messages', $validatedData['messages'] ?? '')
                    ->where('created_at', '>=', now()->subMinutes(1));

                if ($hasVisitColumn) {
                    $recentMessageQuery->where('jobber_visit_id', $visitId);
                }

                if ($messageColumns['twilio_status']) {
                    $recentMessageQuery->where(function ($query) {
                        $query->whereNull('twilio_status')
                            ->orWhereNotIn('twilio_status', ['failed', 'undelivered', 'canceled']);
                    });
                } elseif ($messageColumns['status']) {
                    $recentMessageQuery->where(function ($query) {
                        $query->whereNull('status')
                            ->orWhere('status', '!=', 'failed');
                    });
                }

                if ($recentMessageQuery->exists()) {
                    Log::warning("Duplicate message prevented for {$receiverNumber} (jobber_id: {$validatedData['jobber_id']})");
                    $failedRecipients[] = $receiverNumber.' (duplicate)';

                    continue;
                }

                $payload = [
                    'messages' => $validatedData['messages'] ?? '',
                    'sender_number' => $senderNumber,
                    'receiver_number' => $receiverNumber,
                    'jobber_id' => $validatedData['jobber_id'],
                    'image' => $firstImagePath,
                ];

                if ($hasVisitColumn) {
                    $payload['jobber_visit_id'] = $visitId;
                }

                if ($messageColumns['status']) {
                    $payload['status'] = 'pending';
                }
                if ($messageColumns['error_message']) {
                    $payload['error_message'] = null;
                }
                if ($messageColumns['twilio_sid']) {
                    $payload['twilio_sid'] = null;
                }
                if ($messageColumns['twilio_status']) {
                    $payload['twilio_status'] = 'pending';
                }
                if ($messageColumns['twilio_status_updated_at']) {
                    $payload['twilio_status_updated_at'] = now();
                }
                if ($messageColumns['twilio_error_code']) {
                    $payload['twilio_error_code'] = null;
                }
                if ($messageColumns['twilio_error_message']) {
                    $payload['twilio_error_message'] = null;
                }

                $jobberTextMessage = JobberTextMessage::create($payload);
                $savedMessages[] = $jobberTextMessage;

                // Dispatch job: text message + first image URL
                SendJobberTextMessageJob::dispatch($jobberTextMessage, $messageContent, $firstMediaUrl);

                // Dispatch one job per additional image (no model tracking needed)
                foreach ($extraMediaUrls as $extraMediaUrl) {
                    SendConversationMessageJob::dispatch($receiverNumber, $senderNumber, '', $extraMediaUrl);
                }
            }

            $sentMessages = $savedMessages;

            if (! empty($sentMessages)) {
                $successMessage = count($sentMessages).' message(s) sent successfully!';
                if (! empty($failedRecipients)) {
                    $successMessage .= ' Failed to send to: '.implode(', ', $failedRecipients);
                }

                // Return a success response
                return redirect()->back()->with([
                    'success' => true,
                    'message' => $successMessage,
                    'data' => $sentMessages,
                ]);
            }

            if (! empty($savedMessages)) {
                return redirect()->back()->withErrors([
                    'error' => 'Message saved, but Twilio could not send it to: '.implode(', ', $failedRecipients),
                ])->with([
                    'data' => JobberTextMessage::whereKey(collect($savedMessages)->pluck('id'))->get(),
                ]);
            }

            throw new \RuntimeException('Failed to send messages to all recipients.');
        } catch (\Throwable $e) {

            // Log the error
            Log::error('Failed to send jobber text message: '.$e->getMessage());

            // Return an error response
            return redirect()->back()->withErrors([
                'error' => 'Failed to send the message. Please try again.',
                'details' => $e->getMessage(),
            ]);
        }
    }

    protected function formatNumber(string $number): string
    {
        $cleanedNumber = preg_replace('/[^0-9]/', '', $number);

        if (empty($cleanedNumber)) {
            throw new InvalidArgumentException('The provided phone number is invalid.');
        }

        return '+'.$cleanedNumber;
    }

    protected function getMessageColumnAvailability(): array
    {
        return [
            'status' => Schema::hasColumn('jobber_text_messages', 'status'),
            'sent_at' => Schema::hasColumn('jobber_text_messages', 'sent_at'),
            'error_message' => Schema::hasColumn('jobber_text_messages', 'error_message'),
            'twilio_sid' => Schema::hasColumn('jobber_text_messages', 'twilio_sid'),
            'twilio_status' => Schema::hasColumn('jobber_text_messages', 'twilio_status'),
            'twilio_status_updated_at' => Schema::hasColumn('jobber_text_messages', 'twilio_status_updated_at'),
            'twilio_error_code' => Schema::hasColumn('jobber_text_messages', 'twilio_error_code'),
            'twilio_error_message' => Schema::hasColumn('jobber_text_messages', 'twilio_error_message'),
        ];
    }

    public function show(JobberTextMessage $jobberTextMessage)
    {
        $jobberTextMessage->load('jobber');

        return response()->json($jobberTextMessage);
    }

    public function destroy(JobberTextMessage $jobberTextMessage)
    {
        try {
            // Delete associated image if exists
            if ($jobberTextMessage->image) {
                Storage::disk('public')->delete($jobberTextMessage->image);
            }

            $jobberTextMessage->delete();

            return response()->json(['message' => 'Message deleted successfully!']);
        } catch (\Exception $e) {
            Log::error('Failed to delete jobber text message: '.$e->getMessage());

            return response()->json(['error' => 'Failed to delete message.'], 500);
        }
    }
}
