<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Drop the column separately
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropColumn('conversation_type');
        });

        // Step 2: Recreate the column with ENUM
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->enum('conversation_type', [
                'tenant',
                'owner',
                'vendor',
                'vendor_tenant',
            ])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Drop the column first
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropColumn('conversation_type');
        });

        // Step 2: Recreate with the previous ENUM values
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->enum('conversation_type', [
                'tenant',
                'owner',
                'vendor_tenant',
            ])->nullable();
        });
    }
};
