<?php

namespace App\Http\Controllers;

use App\Models\JobberTextMessage;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        DB::beginTransaction();

        try {
            $hasVisitColumn = JobberTextMessage::hasVisitColumn();
            $visitId = $hasVisitColumn ? ($validatedData['jobber_visit_id'] ?? null) : null;

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
                try {
                    // Check for duplicate messages sent within the last 1 minute to prevent double-submission
                    $recentMessageQuery = JobberTextMessage::where('receiver_number', $receiverNumber)
                        ->where('jobber_id', $validatedData['jobber_id'])
                        ->where('messages', $validatedData['messages'] ?? '')
                        ->where('created_at', '>=', now()->subMinutes(1));

                    if ($hasVisitColumn) {
                        $recentMessageQuery->where('jobber_visit_id', $visitId);
                    }

                    $recentMessage = $recentMessageQuery->first();

                    if ($recentMessage) {
                        Log::warning("Duplicate message prevented for {$receiverNumber} (jobber_id: {$validatedData['jobber_id']})");
                        $failedRecipients[] = $receiverNumber.' (duplicate)';

                        continue;
                    }

                    // Only send SMS if there's content (text or image note)
                    if (! empty($messageContent)) {
                        $twilio->sendMessage(
                            $receiverNumber,
                            $senderNumber,
                            $messageContent,
                            $mediaUrl
                        );
                    }

                    // Save the message to the database AFTER successful send
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

                    $jobberTextMessage = JobberTextMessage::create($payload);

                    $sentMessages[] = $jobberTextMessage;
                } catch (\Exception $e) {
                    Log::error("Failed to send message to {$receiverNumber}: ".$e->getMessage());
                    $failedRecipients[] = $receiverNumber;
                }
            }

            // Commit the transaction if at least one message was sent successfully
            if (! empty($sentMessages)) {
                DB::commit();

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
            } else {
                // All messages failed
                DB::rollBack();
                throw new \Exception('Failed to send messages to all recipients.');
            }

        } catch (\Exception $e) {
            // Roll back the transaction in case of an error
            DB::rollBack();

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
