<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadJobberMessagesPdfRequest;
use App\Models\Jobber;
use App\Services\JobberMessagesPdf;
use Illuminate\Http\Response;

class JobberMessagesPdfController extends Controller
{
    /**
     * Download a Jobber job's text messages as a PDF transcript.
     */
    public function __invoke(DownloadJobberMessagesPdfRequest $request, Jobber $job, JobberMessagesPdf $messagesPdf): Response
    {
        $pdf = $messagesPdf->render($job, $request->input('our_number'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$messagesPdf->fileName($job).'"',
        ]);
    }
}
