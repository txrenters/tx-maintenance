<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-work-order, per-channel automation mute. Holds the channels
     * ('tenant', 'owner', 'vendor') whose automated messages a WOC has switched
     * off for this specific work order. Null/empty = every automation runs
     * (the default). Manual sends are never affected.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->json('paused_automations')->nullable()->after('skip_automated_tasks');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('paused_automations');
        });
    }
};
