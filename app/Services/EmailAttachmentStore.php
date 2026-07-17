<?php

namespace App\Services;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailAttachmentStore
{
    /**
     * Persist already-final (optimized) bytes to disk and create the row.
     * Callers run AttachmentOptimizer::optimize() first.
     */
    public function persist(EmailMessage $message, string $filename, string $mime, string $bytes): EmailAttachment
    {
        $path = 'email-attachments/'.$message->id.'/'.Str::uuid().'-'.$filename;
        Storage::disk('local')->put($path, $bytes);

        return EmailAttachment::create([
            'email_message_id' => $message->id,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($bytes),
            'path' => $path,
        ]);
    }
}
