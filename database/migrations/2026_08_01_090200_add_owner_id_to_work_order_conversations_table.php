<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag owner-thread messages with the owner they belong to, the same way
 * vendor_id already isolates one vendor's thread on a shared work order. A
 * property with several owners otherwise leaks one owner's messages into
 * another owner's portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->after('vendor_id');
            // Existing messages count as read so no owner opens the portal to a
            // pile of historical unread badges.
            $table->boolean('read_by_owner')->default(true)->after('owner_id');

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropIndex(['owner_id']);
            $table->dropColumn(['owner_id', 'read_by_owner']);
        });
    }
};
