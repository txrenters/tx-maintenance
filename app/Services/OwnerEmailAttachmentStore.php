<?php

namespace App\Services;

use App\Models\OwnerEmailAttachment;
use App\Models\OwnerEmailNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OwnerEmailAttachmentStore
{
    public function persist(OwnerEmailNotification $notification, string $filename, string $mime, string $bytes): OwnerEmailAttachment
    {
        $filename = basename($filename);
        $path = 'owner-email-attachments/'.$notification->id.'/'.Str::uuid().'-'.$filename;
        Storage::disk('local')->put($path, $bytes);

        return OwnerEmailAttachment::query()->create([
            'owner_email_notification_id' => $notification->id,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($bytes),
            'path' => $path,
        ]);
    }
}
