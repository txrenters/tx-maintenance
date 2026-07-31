<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track whether the tenant has seen a coordinator message, so the tenant portal
 * can show an unread badge the same way the owner portal does. The tenant
 * thread needs no tenant_id: work_order_id + conversation_type = 'tenant'
 * already identifies it, unlike the owner thread which can hold co-owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            // Existing messages count as read so no tenant opens the portal to a
            // pile of historical unread badges.
            $table->boolean('read_by_tenant')->default(true)->after('read_by_owner');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropColumn('read_by_tenant');
        });
    }
};
