<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\WorkOrder;
use App\Services\InboundTwilioMessageProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TwilioWebhookController extends Controller
{
    public function __construct(protected InboundTwilioMessageProcessor $processor) {}

    public function handle(Request $request)
    {
        $data = $request->all();
        $messageSid = (string) ($request->input('MessageSid') ?: $request->input('SmsSid', ''));
        $messageStatus = (string) $request->input('MessageStatus', '');

        // Some Twilio setups send status callbacks to this same endpoint.
        if ($messageSid !== '' && $messageStatus !== '') {
            return $this->statusCallback($request);
        }

        $this->processor->process($data);

        return response()->noContent();
    }

    public function statusCallback(Request $request)
    {
        try {
            $messageSid = (string) ($request->input('MessageSid') ?: $request->input('SmsSid', ''));
            $messageStatus = (string) $request->input('MessageStatus', '');
            $errorCode = $request->input('ErrorCode');
            $errorMessage = $request->input('ErrorMessage');

            if ($messageSid === '') {
                Log::warning('Twilio status callback missing MessageSid', [
                    'payload' => $request->all(),
                ]);

                return response()->noContent();
            }

            $conversation = Conversation::where('twilio_sid', $messageSid)->latest('id')->first();
            if ($conversation) {
                $conversation->update([
                    'twilio_status' => $messageStatus !== '' ? $messageStatus : null,
                    'twilio_status_updated_at' => now(),
                    'twilio_error_code' => $errorCode ? (string) $errorCode : null,
                    'twilio_error_message' => $errorMessage ?: null,
                ]);

                if (in_array($messageStatus, ['failed', 'undelivered'], true)) {
                    Log::warning('Twilio delivery failure', [
                        'conversation_id' => $conversation->id,
                        'work_order_id' => $conversation->work_order_id,
                        'sid' => $messageSid,
                        'status' => $messageStatus,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'to' => $conversation->receiver_number,
                        'from' => $conversation->sender_number,
                    ]);

                    $workOrder = WorkOrder::find($conversation->work_order_id);
                    $resolvedWorkOrderNo = $workOrder?->work_order_no ?? $conversation->work_order_id;

                    activity()
                        ->performedOn($conversation)
                        ->event('message_undelivered')
                        ->withProperties([
                            'senderNumber' => $conversation->sender_number,
                            'receiverNumber' => $conversation->sender_number,
                            'message' => $conversation->message,
                            'work_order_id' => $conversation->work_order_id,
                            'error_code' => $errorCode ? (string) $errorCode : null,
                            'error_message' => $errorMessage ?: null,
                            'twilio_status' => $messageStatus,
                            'read' => false,
                        ])
                        ->log('Work Order #'.$resolvedWorkOrderNo.' - Message '.ucfirst($messageStatus));
                } else {
                    Log::info('Twilio delivery status updated', [
                        'conversation_id' => $conversation->id,
                        'sid' => $messageSid,
                        'status' => $messageStatus,
                    ]);
                }

                return response()->noContent();
            }

            if (Schema::hasColumn('jobber_text_messages', 'twilio_sid')) {
                $jobberMessage = JobberTextMessage::where('twilio_sid', $messageSid)->latest('id')->first();

                if ($jobberMessage) {
                    $updates = [];

                    if (Schema::hasColumn('jobber_text_messages', 'twilio_status')) {
                        $updates['twilio_status'] = $messageStatus !== '' ? $messageStatus : null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'twilio_status_updated_at')) {
                        $updates['twilio_status_updated_at'] = now();
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'twilio_error_code')) {
                        $updates['twilio_error_code'] = $errorCode ? (string) $errorCode : null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'twilio_error_message')) {
                        $updates['twilio_error_message'] = $errorMessage ?: null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'status')) {
                        $updates['status'] = $messageStatus !== '' ? $messageStatus : null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'error_message')) {
                        $updates['error_message'] = $errorMessage ?: null;
                    }

                    if (! empty($updates)) {
                        $jobberMessage->update($updates);
                    }

                    Log::info('Twilio delivery status updated for jobber message', [
                        'jobber_text_message_id' => $jobberMessage->id,
                        'sid' => $messageSid,
                        'status' => $messageStatus,
                    ]);

                    if (in_array($messageStatus, ['failed', 'undelivered'], true)) {
                        $jobber = Jobber::find($jobberMessage->jobber_id);
                        $resolvedJobNumber = $jobber?->job_number ?? $jobberMessage->jobber_id;

                        activity()
                            ->performedOn($jobberMessage)
                            ->event('message_undelivered')
                            ->withProperties([
                                'senderNumber' => $jobberMessage->sender_number,
                                'receiverNumber' => $jobberMessage->sender_number,
                                'message' => $jobberMessage->messages,
                                'job_id' => $jobberMessage->jobber_id,
                                'error_code' => $errorCode ? (string) $errorCode : null,
                                'error_message' => $errorMessage ?: null,
                                'twilio_status' => $messageStatus,
                                'read' => false,
                            ])
                            ->log('Job #'.$resolvedJobNumber.' - Message '.ucfirst($messageStatus));
                    }

                    return response()->noContent();
                }
            }

            Log::warning('Twilio status callback received for unknown SID', [
                'sid' => $messageSid,
                'status' => $messageStatus,
                'to' => $request->input('To'),
                'from' => $request->input('From'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Twilio status callback processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->noContent();
        }

        return response()->noContent();
    }
}
