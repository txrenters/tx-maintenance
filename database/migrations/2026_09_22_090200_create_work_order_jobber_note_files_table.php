<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos attached to a Jobber note, downloaded onto the public disk.
 *
 * Downloaded rather than hot-linked: Jobber serves note files from
 * ActiveStorage behind short-TTL signed URLs, so a stored url renders for
 * about an hour and is a broken image afterwards — a failure that looks
 * perfect in testing and only shows up days later in production.
 *
 * Deliberately not rows on `attachments`: that table dispatches
 * UploadAttachment to PropertyWare's document API and is read by the owner
 * and tenant portals, so an internal crew photo placed there would be pushed
 * to PropertyWare and shown to owners and tenants.
 *
 * Azure: CREATE TABLE only, touches no existing table, guarded so a re-run is
 * a no-op. Rollback drops the table; the files stay on disk under
 * jobber-note-photos/ and the next sync re-downloads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_jobber_note_files')) {
            return;
        }

        Schema::create('work_order_jobber_note_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_jobber_note_id')
                ->constrained('work_order_jobber_notes')
                ->cascadeOnDelete();

            // Jobber's EncodedId for the file: what makes a re-sync skip a
            // photo it has already downloaded. Capped at 191 for the index.
            $table->string('jobber_file_gid', 191);

            $table->string('file_name')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Relative key on the `public` disk, e.g. jobber-note-photos/x.jpg,
            // served with asset('storage/'.$filename) like every other upload.
            // Null until the download succeeds, so a file Jobber is still
            // processing is retried on the next run rather than lost.
            $table->text('filename')->nullable();

            $table->timestamps();

            // Named explicitly: the generated name would be 76 characters,
            // past MySQL's 64-character limit, and the migration would fail.
            $table->unique(
                ['work_order_jobber_note_id', 'jobber_file_gid'],
                'wo_jobber_note_files_note_gid_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_jobber_note_files');
    }
};
