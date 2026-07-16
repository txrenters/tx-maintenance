<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Magic-link tokens for the no-login tenant portal. Generic on purpose:
     * `purpose` distinguishes tenant-easy-fix photo requests from future uses
     * (e.g. HOA violations), so the same table and portal serve both.
     */
    public function up(): void
    {
        Schema::create('tenant_upload_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('purpose')->default('tenant_easy_fix');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedTinyInteger('notified_count')->default(0);
            $table->timestamps();

            $table->index(['work_order_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_upload_tokens');
    }
};
