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
        Schema::table('task_templates', function (Blueprint $table) {
            $table->string('work_order_type')->nullable()->after('name')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn('work_order_type');
        });
    }
};
