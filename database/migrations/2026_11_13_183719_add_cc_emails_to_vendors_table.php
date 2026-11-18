<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Extra addresses copied on every email the app sends a vendor, e.g. an
     * office inbox when the vendor cannot read the mailbox PropertyWare holds
     * for them (Fantastic Floors, 2026-11-13). JSON list; null = none.
     * Guarded so a re-run after a partial deploy converges instead of failing.
     */
    public function up(): void
    {
        if (Schema::hasColumn('vendors', 'cc_emails')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->json('cc_emails')->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('vendors', 'cc_emails')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('cc_emails');
        });
    }
};
