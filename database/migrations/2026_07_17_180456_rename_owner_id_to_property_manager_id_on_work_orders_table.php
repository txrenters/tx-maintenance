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
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->renameColumn('owner_id', 'property_manager_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreign('property_manager_id')->references('id')->on('owners')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['property_manager_id']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->renameColumn('property_manager_id', 'owner_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
        });
    }
};
