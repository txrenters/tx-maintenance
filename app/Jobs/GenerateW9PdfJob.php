<?php

namespace App\Jobs;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class GenerateW9PdfJob implements ShouldQueue
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
            // Path to your base W9 template
            $templatePath = public_path('w9_form.pdf');

            // === 2️⃣ Create a new filled PDF ===
            $pdf = new Fpdi;
            $pdf->AddPage();
            $pdf->setSourceFile($templatePath);
            $template = $pdf->importPage(1);
            $pdf->useTemplate($template);

            // Set font style
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetMargins(0, 0, 0);

            // === 3️⃣ Fill text fields ===
            $pdf->SetXY(25, 41);
            $pdf->Write(5, $this->formData['w9_entity_name'] ?? '');

            $pdf->SetXY(25, 50);
            $pdf->Write(5, $this->formData['w9_business_name'] ?? '');

            // 3a
            [$x, $y] = match ($this->formData['w9_tax_class'] ?? null) {
                'r1' => [25, 62],
                'r2' => [63, 62],
                'r3' => [89, 62],
                'r4' => [114, 62],
                'r5' => [137, 62],
                'r6' => [25, 66.5],
                'r7' => [25, 79.5],
                default => [null, null],
            };
            $pdf->SetFont('ZapfDingbats', '', 10);
            if ($x !== null && $y !== null) {
                $pdf->SetXY($x, $y);
                $pdf->Write(5, '4');
            }

            if ($this->formData['w9_tax_class1']) {
                $pdf->SetXY(155, 91); // 3b
                $pdf->Write(5, '4');
            }

            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(150, 67);
            $pdf->Write(5, $this->formData['w9_llc_tax_class'] ?? '');

            $pdf->SetXY(57, 80);
            $pdf->Write(5, $this->formData['w9_other_tax_class'] ?? '');

            $pdf->SetXY(192, 67);
            $pdf->Write(5, $this->formData['w9_exempt_payee_code'] ?? '');

            $pdf->SetXY(180, 80);
            $pdf->Write(5, $this->formData['w9_exempt_reporting_code'] ?? '');

            $pdf->SetXY(25, 101);
            $pdf->Write(5, $this->formData['w9_address'] ?? '');

            $pdf->SetXY(25, 110);
            $pdf->Write(5, $this->formData['w9_address2'] ?? '');

            $pdf->SetXY(25, 118);
            $pdf->Write(5, $this->formData['w9_account_list'] ?? '');

            $pdf->SetXY(138, 102);
            $pdf->MultiCell(60, 5, $this->formData['w9_requester_name_and_address'] ?? '', 0, 'L');

            $pdf->SetXY(142, 207);
            $pdf->Write(5, now()->tz('America/Chicago')->format('F d, Y'));

            $pdf->SetFont('Helvetica', 'B', 12);

            $ssn = $this->formData['w9_ssn'];
            $pdf->SetXY(147.5, 134);
            for ($i = 0; $i < strlen($ssn); $i++) {
                $pdf->Write(5, $ssn[$i]);
                if ($i == 2 || $i == 4) {
                    $pdf->SetX($pdf->GetX() + 7); // extra space after 3rd & 5th digits
                } else {
                    $pdf->SetX($pdf->GetX() + 3); // normal space
                }
            }
            $ein = $this->formData['w9_ein'];
            $pdf->SetXY(147.5, 150);
            for ($i = 0; $i < strlen($ein); $i++) {
                $pdf->Write(5, $ein[$i]);
                if ($i == 1) {
                    $pdf->SetX($pdf->GetX() + 7); // extra space after 3rd & 5th digits
                } else {
                    $pdf->SetX($pdf->GetX() + 3); // normal space
                }
            }

            $signatureData = $this->signature;
            $signaturePath = storage_path('app/temp_signature.png');

            if (str_starts_with($signatureData, 'data:image')) {
                $signatureData = explode(',', $signatureData)[1];
            }

            file_put_contents($signaturePath, base64_decode($signatureData));

            // Add to PDF
            $pdf->Image($signaturePath, 35, 201, 40, 0, 'PNG'); // (x, y, width)

            // === 4️⃣ Save to storage ===
            $fileName = 'form_w9_'.time().'.pdf';
            $storagePath = storage_path("app/public/{$fileName}");

            $pdf->Output($storagePath, 'F'); // “F” = file save

            Log::info('Owner w9 PDF generated successfully', [
                'Building Name' => $this->buildingData['name'] ?? 'Unknown',
                'Filename' => $fileName,
                'File Path' => $storagePath,
            ]);

            // Optional: Upload to Propertyware (if needed)
            if (file_exists($storagePath)) {
                $this->uploadToPropertyware($storagePath, $fileName, $this->buildingData['id']);

                if (file_exists($signaturePath)) {
                    unlink($signaturePath);
                }
                if (file_exists($storagePath)) {
                    unlink($storagePath);
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to generate w9 PDF: '.$e->getMessage(), [
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
                Log::info('Property onboarding w9 PDF uploaded to Propertyware successfully', [
                    'Building ID' => $buildingId,
                    'Filename' => $fileName,
                    'Response' => $response->json(),
                ]);
            } else {
                Log::error('Failed to upload w9 PDF to Propertyware', [
                    'Building ID' => $buildingId,
                    'Filename' => $fileName,
                    'Status' => $response->status(),
                    'Response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while uploading w9 PDF to Propertyware: '.$e->getMessage(), [
                'Building ID' => $buildingId,
                'Filename' => $fileName,
            ]);
        }
    }
}
