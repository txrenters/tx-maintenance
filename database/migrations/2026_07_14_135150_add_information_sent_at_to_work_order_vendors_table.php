<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks when the Work Order Information notification (email + PropertyWare
     * upload + assignment SMS) was sent for an assignment, so the job can claim
     * it atomically and never notify the same vendor twice on a re-run/retry.
     */
    public function up(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->timestamp('information_sent_at')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->dropColumn('information_sent_at');
        });
    }
};
