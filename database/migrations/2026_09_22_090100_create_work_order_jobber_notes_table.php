<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes read from the Jobber job behind a THMP work order.
 *
 * Deliberately not rows on `work_order_notes`. That table is reconciled
 * against PropertyWare by WorkOrderNoteSyncService, which deletes every row
 * PropertyWare did not report and that has no local author (user_id null) —
 * precisely the shape a Jobber note has. It also relinks unowned rows to
 * PropertyWare notes by text fingerprint, which short repeated notes ("Done",
 * "No access") would collide on, and it feeds notes:push-pending. A Jobber
 * note stored there would be deleted, re-created, possibly mis-linked, and at
 * risk of being pushed into PropertyWare. Its own table makes all four
 * impossible instead of guarded in five call sites.
 *
 * The unique key is (work_order_id, jobber_note_gid), not the gid alone: one
 * Jobber job can be linked to several work orders and each keeps its own copy
 * of the note.
 *
 * Azure: CREATE TABLE only, touches no existing table, guarded so a re-run is
 * a no-op. Rollback drops the table; the next sync re-fetches from Jobber, so
 * nothing is permanently lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_jobber_notes')) {
            return;
        }

        Schema::create('work_order_jobber_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();

            // Jobber's EncodedId for the note. Capped at 191 so the composite
            // unique below stays inside MySQL's index byte limit on utf8mb4.
            $table->string('jobber_note_gid', 191);

            // Which JobNoteUnion member this came back as: JobNote,
            // ClientNote, QuoteNote or RequestNote. Only JobNote carries file
            // attachments; the rest are message-only.
            $table->string('note_type', 40)->default('JobNote');

            // The Jobber job it was read from, kept for tracing which link found it.
            $table->string('jobber_job_gid', 191)->nullable();

            $table->longText('message')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_type', 40)->nullable();
            $table->boolean('pinned')->default(false);

            // Jobber's own timestamps, distinct from this row's created_at,
            // which is only when the sync first copied it here.
            $table->timestamp('jobber_created_at')->nullable();
            $table->timestamp('jobber_last_edited_at')->nullable();

            $table->timestamps();

            // Named explicitly: the generated name would run past MySQL's
            // 64-character identifier limit and fail the migration, which on
            // this deploy takes the site down with it.
            $table->unique(['work_order_id', 'jobber_note_gid'], 'wo_jobber_notes_wo_gid_unique');
            $table->index(['work_order_id', 'jobber_created_at'], 'wo_jobber_notes_wo_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_jobber_notes');
    }
};
