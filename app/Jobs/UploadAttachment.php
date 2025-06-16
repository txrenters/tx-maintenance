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
            'is_publish_to_tenant_portal' => $this->data->is_publish_to_tenant_portal == 'Yes',
            'is_publish_to_owner_portal' => $this->data->is_publish_to_owner_portal == 'Yes',
        ];

        $propertyware = new PropertyWareService;

        try {
            $uploaded = $propertyware->uploadVendorAttachment($this->data->work_order_id, $validatedData);

            Log::info('Work order attachment has been uploaded', [
                'work order no' => $this->data->work_order->work_order_no,
                'filename' => $this->data->filename,
            ]);

        } catch (Exception $e) {
            Log::error('Upload failed', [
                'filename' => $this->data->filename,
                'message' => $e->getMessage(),
            ]);
            throw $e; // allows retry
        }
    }
}
