<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The claim stamp for the tenant intake notification, mirroring
     * `owner_service_request_notified_at`. It is what stops a queue redelivery
     * from texting the tenant twice about the same request.
     *
     * No backfill is needed: the notification is only ever dispatched for a work
     * order the import has just created, so the existing backlog cannot be
     * reached by it.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('tenant_service_request_notified_at')->nullable()->after('owner_service_request_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('tenant_service_request_notified_at');
        });
    }
};
