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
        if (Schema::hasColumn('attachments', 'thumb_path')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('attachments', 'thumb_path')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};
