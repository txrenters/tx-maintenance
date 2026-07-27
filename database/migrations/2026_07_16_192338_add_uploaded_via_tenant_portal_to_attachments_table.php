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
            // Marks photos the tenant uploaded through the no-login tenant
            // portal, so the portal can list exactly (and only) those.
            $table->boolean('uploaded_via_tenant_portal')->default(false)->after('is_publish_to_tenant_portal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('uploaded_via_tenant_portal');
        });
    }
};
