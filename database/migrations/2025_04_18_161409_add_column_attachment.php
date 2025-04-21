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
            $table->boolean('is_private')->default(false)->after('type');
            $table->boolean('is_publish_to_owner_portal')->default(false)->after('is_private');
            $table->boolean('is_publish_to_tenant_portal')->default(false)->after('is_publish_to_owner_portal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['is_private', 'is_publish_to_owner_portal', 'is_publish_to_tenant_portal']);
        });
    }
};
