<?php

namespace App\Jobs;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateOnboardingPdfJob implements ShouldQueue
{
    use Queueable;

    public $signature;

    public $formData;

    public $buildingData;

    public $propertywareData;

    public $ownerName;

    public $tries = 3;

    public $backoff = 60; // retry after 60s

    /**
     * Create a new job instance.
     */
    public function __construct($signature, $formData, $buildingData, $propertywareData, $ownerName = null)
    {
        $this->signature = $signature;
        $this->formData = $formData;
        $this->buildingData = $buildingData;
        $this->propertywareData = $propertywareData;
        $this->ownerName = $ownerName;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Prepare data for PDF
            $pdfData = [
                'signature' => $this->signature,
                'formData' => $this->formData,
                'buildingData' => $this->buildingData,
                'propertywareData' => $this->propertywareData,
                'ownerName' => $this->ownerName,
                'generated_at' => now()->tz('America/Chicago')->format('Y-m-d h:i A'),
            ];

            // Generate PDF
            $pdf = Pdf::loadView('onboarding_process', $pdfData);

            // Create filename with building ID and timestamp
            $buildingId = $this->buildingData['id'] ?? 'unknown';
            $fileName = 'Management_Onboarding_Information_Form_'.date('YmdHis').'.pdf';

            // Save to storage directory
            $storagePath = storage_path('app/public/'.$fileName);
            $pdf->save($storagePath);

            Log::info('Property onboarding PDF generated successfully', [
                'Building ID' => $buildingId,
                'Building Name' => $this->buildingData['name'] ?? 'Unknown',
                'Filename' => $fileName,
                'File Path' => $storagePath,
            ]);

            if (file_exists($storagePath)) {
                $this->uploadToPropertyware($storagePath, $fileName, $buildingId);

                DB::table('onboarding_clients')->insert([
                    'name' => $this->ownerName,
                    'building_name' => $this->buildingData['name'] ?? 'Unknown',
                    'filename' => $fileName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (file_exists($storagePath)) unlink($storagePath);
            }

        } catch (\Exception $e) {
            Log::error('Failed to generate onboarding PDF: '.$e->getMessage(), [
                'Building ID' => $this->buildingData['id'] ?? 'unknown',
                'Error' => $e->getMessage(),
                'Stack Trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function uploadToPropertyware($filePath, $fileName, $buildingId)
    {
        try {
            $fileContents = file_get_contents($filePath);

            $headers = [
                'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
                'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
                'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
            ];

            $response = Http::withHeaders($headers)
                ->attach('file', $fileContents, $fileName)
                ->post('https://api.propertyware.com/pw/api/rest/v1/docs', [
                    'entityId' => $buildingId,
                    'entityType' => 'Building',
                    'publishToOwnerPortal' => true,
                ]);

            if ($response->successful()) {
                Log::info('Property onboarding PDF uploaded to Propertyware successfully', [
                    'Building ID' => $buildingId,
                    'Filename' => $fileName,
                    'Response' => $response->json(),
                ]);
            } else {
                Log::error('Failed to upload PDF to Propertyware', [
                    'Building ID' => $buildingId,
                    'Filename' => $fileName,
                    'Status' => $response->status(),
                    'Response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while uploading PDF to Propertyware: '.$e->getMessage(), [
                'Building ID' => $buildingId,
                'Filename' => $fileName,
            ]);
        }
    }
}
