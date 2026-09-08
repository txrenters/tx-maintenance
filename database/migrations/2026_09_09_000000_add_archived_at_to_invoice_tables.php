<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice is archived rather than deleted: the row and its uploaded file
 * both survive, and the office can put it back.
 *
 * archived_at is an explicit column instead of SoftDeletes' deleted_at
 * because several invoice reads run withoutGlobalScopes() — the vendor
 * portal most of all — which a soft-delete scope would silently bypass,
 * leaving archived invoices on show. A plain column forces every caller to
 * say what it wants.
 *
 * archived_by_user_id carries no FK constraint: the invoice outlives the
 * staff account that archived it, and a missing user simply means the
 * restore view shows no name.
 *
 * Guarded column by column so a re-run after a partial deploy or a
 * startup.sh double-run converges instead of erroring.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['invoices', 'jobber_job_invoices'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'archived_at')) {
                    $blueprint->timestamp('archived_at')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'archived_by_user_id')) {
                    $blueprint->unsignedBigInteger('archived_by_user_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'archived_at')) {
                    $blueprint->dropColumn('archived_at');
                }
                if (Schema::hasColumn($table, 'archived_by_user_id')) {
                    $blueprint->dropColumn('archived_by_user_id');
                }
            });
        }
    }
};
