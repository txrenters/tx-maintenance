<?php

namespace App\Jobs;

use App\Services\Images\ThumbnailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class GenerateThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;

    public $backoff = 15;

    public $timeout = 120;

    /**
     * Takes a class name and id rather than the model itself, deliberately
     * skipping SerializesModels: Attachments carries AttachmentScope, which is
     * reapplied on re-resolution. Under the sync driver (local and the whole
     * test suite) auth() is still populated, so a WOC uploading on behalf of a
     * vendor would re-resolve to null and the job would silently do nothing.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        public string $modelClass,
        public int $modelId,
        public string $sourceColumn = 'filename',
        public string $disk = 'public',
    ) {}

    public function uniqueId(): string
    {
        return $this->modelClass.':'.$this->modelId;
    }

    public function handle(ThumbnailService $thumbnails): void
    {
        $model = $this->modelClass::withoutGlobalScopes()->find($this->modelId);

        if ($model === null) {
            return;
        }

        // A missing thumbnail is cosmetic — the accessor falls back to the
        // original and the upload itself already succeeded — so a failure here
        // must never surface to the user or burn a retry re-encoding.
        try {
            if ($thumbnails->generate($model, $this->sourceColumn, $this->disk)) {
                return;
            }

            if ($thumbnails->looksTransient($model, $this->sourceColumn, $this->disk)) {
                $this->release(30);
            }
        } catch (\Throwable $e) {
            Log::warning('Thumbnail job failed', [
                'model' => $this->modelClass,
                'id' => $this->modelId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
