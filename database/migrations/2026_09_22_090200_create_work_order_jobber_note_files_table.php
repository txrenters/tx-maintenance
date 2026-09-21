<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which note brought which photo in.
 *
 * The photo itself is an ordinary row on `attachments`, so it shows up on the
 * work order's Attachments tab beside everything else, which is where staff
 * asked for it. This table is only the bookkeeping between the two: it records
 * the Jobber file id a given note carried and the attachment it produced, so a
 * note deleted in Jobber can take its photos off the tab with it.
 *
 * Deliberately not columns on `attachments`: a photo there needs to behave
 * like any other attachment, and hanging note bookkeeping off it would put
 * Jobber's shape into a table five other features already read.
 *
 * Azure: CREATE TABLE only, touches no existing table, guarded so a re-run is
 * a no-op. Rollback drops the table; the attachments stay on the tab and the
 * link to their notes is lost, which the next sync does not rebuild — the
 * photos are already downloaded, so it has nothing to re-link.
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

            // Jobber's EncodedId for the file.
            $table->string('jobber_file_gid', 191);

            // The attachment row this became. Nulled rather than cascading if
            // the attachment is deleted by hand on the tab: staff removing a
            // photo should not quietly delete the note's record of it.
            $table->foreignId('attachment_id')
                ->nullable()
                ->constrained('attachments')
                ->nullOnDelete();

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
