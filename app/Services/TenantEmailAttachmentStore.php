<?php

namespace App\Services;

use App\Models\TenantEmailAttachment;
use App\Models\TenantEmailNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TenantEmailAttachmentStore
{
    public function persist(TenantEmailNotification $notification, string $filename, string $mime, string $bytes): TenantEmailAttachment
    {
        $filename = basename($filename);
        $path = 'tenant-email-attachments/'.$notification->id.'/'.Str::uuid().'-'.$filename;
        Storage::disk('local')->put($path, $bytes);

        return TenantEmailAttachment::query()->create([
            'tenant_email_notification_id' => $notification->id,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($bytes),
            'path' => $path,
        ]);
    }
}
