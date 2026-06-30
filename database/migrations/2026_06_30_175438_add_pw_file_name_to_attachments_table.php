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
        Schema::table('attachments', function (Blueprint $table) {
            // The exact filename this attachment was uploaded to PropertyWare as.
            // Lets the document pull skip re-importing our own uploads.
            $table->string('pw_file_name')->nullable()->after('filename');
            $table->index(['work_order_id', 'pw_file_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['work_order_id', 'pw_file_name']);
            $table->dropColumn('pw_file_name');
        });
    }
};
