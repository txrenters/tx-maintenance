<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an attachment as a photo fetched from a Jobber note.
 *
 * Two jobs, both of which need a column rather than a convention:
 *
 * 1. Dedupe. The sync re-reads the same notes every half hour, so the Jobber
 *    file id is what stops a photo being downloaded and inserted again.
 *
 * 2. Keeping it out of PropertyWare. Nothing pushes an attachment on insert,
 *    but RepairAttachmentPwUploads runs daily and sweeps every row whose
 *    pw_file_name is still null, which a Jobber photo's always will be. Its
 *    only existing exclusion is a match on the literal title "HOA violation
 *    notice". A column the sweep can test is sturdier than giving these rows
 *    a magic title someone could reasonably edit.
 *
 * These are the crew's internal working photos: they stay off PropertyWare and
 * off the owner and tenant portals, which the is_publish_* columns already
 * default to false for.
 *
 * Azure: ADD COLUMN only, nullable, no default backfill, guarded so a re-run
 * is a no-op. Every existing row keeps a null and behaves exactly as before.
 * Rollback drops the column; the photos stay, and the sweep would then see
 * them, so re-apply before re-enabling the sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attachments', 'jobber_note_file_gid')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->string('jobber_note_file_gid', 191)->nullable()->after('pw_file_name');
            $table->index(['work_order_id', 'jobber_note_file_gid'], 'attachments_wo_jobber_file_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('attachments', 'jobber_note_file_gid')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('attachments_wo_jobber_file_idx');
            $table->dropColumn('jobber_note_file_gid');
        });
    }
};
