<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadConversationPdfRequest;
use App\Models\WorkOrder;
use App\Services\ConversationPdf;
use Illuminate\Http\Response;

class ConversationPdfController extends Controller
{
    /**
     * Download the conversation on screen as a PDF transcript.
     */
    public function __invoke(DownloadConversationPdfRequest $request, WorkOrder $workOrder, ConversationPdf $conversationPdf): Response
    {
        $title = $request->string('title')->toString();

        $pdf = $conversationPdf->render(
            $workOrder,
            $title,
            array_map('intval', $request->input('message_ids', [])),
            $request->input('our_number'),
            $request->input('participants', []),
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$conversationPdf->fileName($workOrder, $title).'"',
        ]);
    }
}
