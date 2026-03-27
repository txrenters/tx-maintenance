<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Services\TwilioService;
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
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120', // 5MB max
        ]);

        // Validate that either message or image is provided
        if (empty($validatedData['messages']) && ! $request->hasFile('image')) {
            return response()->json(['error' => 'Please provide either a message or an image.'], 422);
        }

        // Format phone numbers ensuring proper + prefix
        $senderNumber = $validatedData['sender_number'];
        $receiverNumbers = array_map([$this, 'formatNumber'], $validatedData['receiver_numbers']);

        try {
            $hasVisitColumn = JobberTextMessage::hasVisitColumn();
            $visitId = $hasVisitColumn ? ($validatedData['jobber_visit_id'] ?? null) : null;
            $messageColumns = $this->getMessageColumnAvailability();

            // Handle image upload if present
            $imagePath = null;
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $originalName = $image->getClientOriginalName();
                $filename = time().'_'.$originalName;

                // Store image in storage/app/public/jobber_images
                $imagePath = $image->storeAs('jobber_images', $filename, 'public');
            }

            $twilio = new TwilioService;
            $savedMessages = [];
            $sentMessages = [];
            $failedRecipients = [];

            // Prepare message content for Twilio
            $messageContent = $validatedData['messages'] ?? '';

            // If there's an image, add a note about it in the SMS
            if ($imagePath) {
                $imageNote = $messageContent ? "\n\n📷 Image attached" : '📷 Image sent';
                $messageContent = $messageContent.$imageNote;
            }

            // Prepare media URL for MMS if image exists
            $mediaUrl = null;
            if ($imagePath) {
                $mediaUrl = asset('storage/'.$imagePath);
            }

            // Send message to each recipient
            foreach ($receiverNumbers as $receiverNumber) {
                $jobberTextMessage = null;

                try {
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

                    $recentMessage = $recentMessageQuery->first();

                    if ($recentMessage) {
                        Log::warning("Duplicate message prevented for {$receiverNumber} (jobber_id: {$validatedData['jobber_id']})");
                        $failedRecipients[] = $receiverNumber.' (duplicate)';

                        continue;
                    }

                    $payload = [
                        'messages' => $validatedData['messages'] ?? '',
                        'sender_number' => $senderNumber,
                        'receiver_number' => $receiverNumber,
                        'jobber_id' => $validatedData['jobber_id'],
                        'image' => $imagePath,
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

                    // Only send SMS if there's content (text or image note)
                    $twilioMessage = null;
                    if (! empty($messageContent)) {
                        $twilioMessage = $twilio->sendMessage(
                            $receiverNumber,
                            $senderNumber,
                            $messageContent,
                            $mediaUrl
                        );
                    }

                    $successUpdates = [];
                    if ($messageColumns['status']) {
                        $successUpdates['status'] = $twilioMessage->status ?? 'queued';
                    }
                    if ($messageColumns['sent_at']) {
                        $successUpdates['sent_at'] = now();
                    }
                    if ($messageColumns['error_message']) {
                        $successUpdates['error_message'] = null;
                    }
                    if ($messageColumns['twilio_sid']) {
                        $successUpdates['twilio_sid'] = $twilioMessage->sid ?? null;
                    }
                    if ($messageColumns['twilio_status']) {
                        $successUpdates['twilio_status'] = $twilioMessage->status ?? 'queued';
                    }
                    if ($messageColumns['twilio_status_updated_at']) {
                        $successUpdates['twilio_status_updated_at'] = now();
                    }
                    if ($messageColumns['twilio_error_code']) {
                        $successUpdates['twilio_error_code'] = null;
                    }
                    if ($messageColumns['twilio_error_message']) {
                        $successUpdates['twilio_error_message'] = null;
                    }

                    $jobberTextMessage->update($successUpdates);

                    $sentMessages[] = $jobberTextMessage->fresh();
                } catch (\Throwable $e) {
                    Log::error("Failed to send message to {$receiverNumber}: ".$e->getMessage());

                    if ($jobberTextMessage) {
                        $jobber = Jobber::find($validatedData['jobber_id']);
                        $resolvedJobNumber = $jobber?->job_number ?? $validatedData['jobber_id'];

                        activity()
                            ->performedOn($jobberTextMessage)
                            ->event('message_undelivered')
                            ->withProperties([
                                'senderNumber' => $validatedData['sender_number'],
                                'receiverNumber' => $validatedData['sender_number'],
                                'message' => $validatedData['messages'] ?? '',
                                'job_id' => $validatedData['jobber_id'],
                                'error_code' => $e->getCode() ? (string) $e->getCode() : null,
                                'error_message' => $e->getMessage(),
                                'twilio_status' => 'failed',
                                'read' => false,
                            ])
                            ->log('Job #'.$resolvedJobNumber.' - Message Failed');

                        $failedUpdates = [];
                        if ($messageColumns['status']) {
                            $failedUpdates['status'] = 'failed';
                        }
                        if ($messageColumns['sent_at']) {
                            $failedUpdates['sent_at'] = now();
                        }
                        if ($messageColumns['error_message']) {
                            $failedUpdates['error_message'] = $e->getMessage();
                        }
                        if ($messageColumns['twilio_sid']) {
                            $failedUpdates['twilio_sid'] = null;
                        }
                        if ($messageColumns['twilio_status']) {
                            $failedUpdates['twilio_status'] = 'failed';
                        }
                        if ($messageColumns['twilio_status_updated_at']) {
                            $failedUpdates['twilio_status_updated_at'] = now();
                        }
                        if ($messageColumns['twilio_error_code']) {
                            $failedUpdates['twilio_error_code'] = $e->getCode() ? (string) $e->getCode() : null;
                        }
                        if ($messageColumns['twilio_error_message']) {
                            $failedUpdates['twilio_error_message'] = $e->getMessage();
                        }

                        $jobberTextMessage->update($failedUpdates);
                    }

                    $failedRecipients[] = $receiverNumber;
                }
            }

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
