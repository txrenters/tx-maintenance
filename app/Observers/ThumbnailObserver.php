<?php

namespace App\Observers;

use App\Services\Images\ThumbnailService;
use Illuminate\Database\Eloquent\Model;

/**
 * Removes the generated thumbnail when its record is deleted. Attached to the
 * model rather than to the individual controllers so a future delete path
 * cannot orphan thumbnails; the existing manual deletes of the original file
 * are left alone.
 *
 * Note attachments.work_order_id is cascadeOnDelete at the database level, so
 * deleting a work order bypasses Eloquent events entirely — that already
 * orphans the originals today, and is not made worse here.
 */
class ThumbnailObserver
{
    public function __construct(private ThumbnailService $thumbnails) {}

    public function deleting(Model $model): void
    {
        $this->thumbnails->delete($model);
    }
}
