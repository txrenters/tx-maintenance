<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderDocuments extends Model
{
    protected $table = 'work_order_documents';

    protected $guarded = [];

    /**
     * Whether a PropertyWare document with this file name is already represented
     * for the work order — either as a previously-pulled document or as one of
     * our own uploaded attachments. Used by the document pull to avoid storing
     * duplicates (re-pulled uploads and PropertyWare's repeated system files).
     */
    public static function isDuplicateForWorkOrder(int $workOrderId, ?string $fileName): bool
    {
        if (! $fileName) {
            return false;
        }

        $alreadyPulled = static::query()
            ->where('work_order_id', $workOrderId)
            ->where('file_name', $fileName)
            ->exists();

        if ($alreadyPulled) {
            return true;
        }

        return Attachments::withoutGlobalScopes()
            ->where('work_order_id', $workOrderId)
            ->where('pw_file_name', $fileName)
            ->exists();
    }

    /**
     * Whether a file name is one of PropertyWare's auto-generated thumbnails
     * (prefixed with "THMP_"). These are previews of another document already
     * synced, so we never pull them as separate documents.
     */
    public static function isThumbnailFileName(?string $fileName): bool
    {
        return $fileName !== null && str_starts_with($fileName, 'THMP_');
    }
}
