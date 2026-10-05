<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatbotWebhookRequest;
use App\Services\ChatbotEventProcessor;
use Illuminate\Http\Response;

class ChatbotWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ChatbotWebhookRequest $request, ChatbotEventProcessor $processor): Response
    {
        $processor->process($request->validated());

        return response()->noContent();
    }
}
