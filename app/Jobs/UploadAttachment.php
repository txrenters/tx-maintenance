<?php

namespace App\Jobs;

use App\Services\PropertyWareService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class UploadAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $data;

    public $tries = 3;

    public $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $validatedData = [
            'title' => $this->data->title,
            'type' => $this->data->type,
            'filename' => $this->data->filename,
            'filetype' => $this->data->filetype,
            'is_publish_to_tenant_portal' => $this->data->is_publish_to_tenant_portal,
            'is_publish_to_owner_portal' => $this->data->is_publish_to_owner_portal,
        ];

        // Optimize image before uploading to PropertyWare (huge win for iPhone photos)
        $this->optimizeImage($validatedData['filename']);

        $propertyware = new PropertyWareService;

        try {
            $uploaded = $propertyware->uploadVendorAttachment($this->data->work_order_id, $validatedData);

        } catch (Exception $e) {
            Log::error('Upload failed', [
                'filename' => $this->data->filename,
                'message' => $e->getMessage(),
            ]);
            throw $e; // allows retry
        }
    }

    /**
     * Optimize image files to reduce file size before uploading
     */
    protected function optimizeImage(string $filename): void
    {
        $absolutePath = public_path('storage/'.$filename);

        if (! file_exists($absolutePath)) {
            return;
        }

        $mimeType = mime_content_type($absolutePath);
        $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        if (! $isImage) {
            return; // Skip non-image files
        }

        try {
            $originalSize = filesize($absolutePath);

            $optimizerChain = OptimizerChainFactory::create();
            $optimizerChain->optimize($absolutePath);

            $newSize = filesize($absolutePath);
            $savedBytes = $originalSize - $newSize;
            $savedPercent = $originalSize > 0 ? round(($savedBytes / $originalSize) * 100, 2) : 0;

            Log::info('Image optimized successfully', [
                'filename' => $filename,
                'original_size' => round($originalSize / 1024, 2).'KB',
                'new_size' => round($newSize / 1024, 2).'KB',
                'saved' => round($savedBytes / 1024, 2).'KB',
                'saved_percent' => $savedPercent.'%',
            ]);
        } catch (Exception $e) {
            Log::warning('Image optimization failed, proceeding with original file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            // Continue with original file if optimization fails
        }
    }
}
