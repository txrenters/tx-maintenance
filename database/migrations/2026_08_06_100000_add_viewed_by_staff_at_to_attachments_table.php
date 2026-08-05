<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // Powers the "new attachment" number badge on the Attachments tab:
            // null = not yet opened by staff. Stamped when an admin/WOC loads
            // the tab, and immediately at creation for staff's own uploads.
            $table->timestamp('viewed_by_staff_at')->nullable()->after('uploaded_via_tenant_portal');
        });

        // Everything that already exists predates the badge — mark it seen so
        // the deploy doesn't light up a badge on every work order at once.
        DB::table('attachments')->update(['viewed_by_staff_at' => now()]);

        // The tenant, owner and vendor portals attribute uploads to the
        // requester's login, but tenants (and some owners) have no user
        // account — the NOT NULL column made those uploads fail outright,
        // which is why portal photos could go missing entirely.
        Schema::table('attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * user_id stays nullable on rollback: restoring NOT NULL would fail on any
     * row the fix has since allowed in.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('viewed_by_staff_at');
        });
    }
};
