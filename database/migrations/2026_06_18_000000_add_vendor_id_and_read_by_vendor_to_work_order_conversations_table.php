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
        Schema::table('work_order_conversations', function (Blueprint $table) {
            // Associates a message with a specific vendor so a numberless vendor's
            // thread can still be shown on their portal.
            $table->foreignId('vendor_id')->nullable()->after('work_order_id')
                ->constrained('vendors')->nullOnDelete();
            // Whether the vendor has seen this message (drives the portal "new" badge).
            $table->boolean('read_by_vendor')->default(true)->after('is_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn('read_by_vendor');
        });
    }
};
