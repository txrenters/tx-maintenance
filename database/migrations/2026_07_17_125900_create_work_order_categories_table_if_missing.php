<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * work_order_categories has existed in production since before migrations
     * covered it, so fresh databases (tests, new environments) were missing it
     * entirely. Create it only when absent; production is untouched.
     */
    public function up(): void
    {
        if (Schema::hasTable('work_order_categories')) {
            return;
        }

        Schema::create('work_order_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Pre-existing production table; never drop it on rollback.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
