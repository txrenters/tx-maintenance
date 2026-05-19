<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_recommendations', function (Blueprint $table) {
            $table->string('vendor_source')->nullable()->after('vendor_category');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_recommendations', function (Blueprint $table) {
            $table->dropColumn('vendor_source');
        });
    }
};
