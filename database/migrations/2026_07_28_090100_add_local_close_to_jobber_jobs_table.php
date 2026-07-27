<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record a close made from our side of the Jobber jobs page.
 *
 * These live in their own columns rather than in `job_status` on purpose.
 * `job_status` is owned by Jobber: both the importer and the JOB_UPDATE webhook
 * overwrite it with whatever Jobber last said. Writing a close there would be
 * silently undone on the next sync, quietly reopening a job the office had
 * already finished with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('jobber_jobs', 'closed_at')) {
            return;
        }

        Schema::table('jobber_jobs', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->index();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('jobber_jobs', 'closed_at')) {
            return;
        }

        Schema::table('jobber_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_user_id');
            // Drop the index before the column it covers. MySQL removes it with
            // the column, but SQLite aborts the whole rollback mid-way, which
            // leaves the table half-migrated and the migration still recorded
            // as applied.
            $table->dropIndex(['closed_at']);
            $table->dropColumn(['closed_at', 'close_reason']);
        });
    }
};
