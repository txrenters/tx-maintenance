<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * One-time cleanup: remove the inert local-only HOA violation rows left behind
     * while getLatestWorkOrderForBuilding() was failing its SOAP encoding (missing
     * pageNumber, fixed in this deploy) — every HOA upload needing a PropertyWare
     * create since 2026-08-05 fell back to a local-only row. These rows never
     * synced (no propertyware_id, no work_order_no) and can never be pushed to
     * PropertyWare; the 5231 Shadow Breeze notice was re-created there as WO
     * #43749. Local-only + HOA token identifies exactly this fallback set — every
     * other work order originates in PropertyWare and carries both IDs.
     */
    public function up(): void
    {
        $orphans = DB::table('work_orders')
            ->whereNull('propertyware_id')
            ->whereNull('work_order_no')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('tenant_upload_tokens')
                    ->whereColumn('tenant_upload_tokens.work_order_id', 'work_orders.id')
                    ->where('tenant_upload_tokens.purpose', 'hoa_violation');
            })
            ->get();

        foreach ($orphans as $orphan) {
            DB::table('tenant_upload_tokens')->where('work_order_id', $orphan->id)->delete();
            DB::table('attachments')->where('work_order_id', $orphan->id)->delete();
            DB::table('work_order_tasks')->where('work_order_id', $orphan->id)->delete();
            DB::table('work_order_notes')->where('work_order_id', $orphan->id)->delete();
            DB::table('work_orders')->where('id', $orphan->id)->delete();

            Log::info('Deleted orphan local-only HOA violation work order.', [
                'work_order_id' => $orphan->id,
                'building_id' => $orphan->building_id,
            ]);
        }
    }

    public function down(): void
    {
        // One-way data cleanup; nothing to restore.
    }
};
