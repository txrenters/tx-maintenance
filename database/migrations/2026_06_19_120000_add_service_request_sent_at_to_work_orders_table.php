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
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('service_request_sent_at')->nullable();
        });

        // Mark every existing work order as already notified so enabling this
        // feature does not email vendors for the entire historical backlog at
        // once. Only service requests imported after this point will send.
        DB::table('work_orders')->update(['service_request_sent_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('service_request_sent_at');
        });
    }
};
