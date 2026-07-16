<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Fresh-start guard for the tenant portal: any work order already sitting
     * in "Checking for Tenant Easy Fix" at deploy time gets a pre-completed
     * token, so the scheduled sender treats the existing backlog as handled
     * and never texts it. Only work orders that ENTER the status after this
     * deploy will be sent a portal link.
     */
    public function up(): void
    {
        // NOTE: the table really is singular `service_status` (see the
        // 2025_02_16 create migration) even though the model is ServiceStatus.
        $statusId = DB::table('service_status')
            ->where('name', 'Checking for Tenant Easy Fix')
            ->value('id');

        if (! $statusId) {
            return;
        }

        $now = now();

        DB::table('work_orders')
            ->where('service_status_id', $statusId)
            ->whereNotIn('id', function ($query) {
                $query->select('work_order_id')
                    ->from('tenant_upload_tokens')
                    ->where('purpose', 'tenant_easy_fix');
            })
            ->pluck('id')
            ->each(function (int $workOrderId) use ($now) {
                DB::table('tenant_upload_tokens')->insert([
                    'token' => Str::random(48),
                    'work_order_id' => $workOrderId,
                    'purpose' => 'tenant_easy_fix',
                    // Pre-completed: the link is never sent and never reminded.
                    'completed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /**
     * Data backfill; nothing meaningful to reverse.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
