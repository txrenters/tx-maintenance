<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time cleanup: remove the inert local-only HOA violation row left behind by
     * the 2026-08-04 failed PropertyWare create ("Location is invalid"). The notice was
     * re-created in PropertyWare as WO #43704; this row never synced (no propertyware_id,
     * no work_order_no) and can never be pushed to PropertyWare.
     */
    public function up(): void
    {
        $orphan = DB::table('work_orders')
            ->where('id', 36391)
            ->whereNull('propertyware_id')
            ->whereNull('work_order_no')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('tenant_upload_tokens')
                    ->whereColumn('tenant_upload_tokens.work_order_id', 'work_orders.id')
                    ->where('tenant_upload_tokens.purpose', 'hoa_violation');
            })
            ->first();

        if ($orphan === null) {
            return;
        }

        DB::table('tenant_upload_tokens')->where('work_order_id', $orphan->id)->delete();
        DB::table('attachments')->where('work_order_id', $orphan->id)->delete();
        DB::table('work_order_tasks')->where('work_order_id', $orphan->id)->delete();
        DB::table('work_order_notes')->where('work_order_id', $orphan->id)->delete();
        DB::table('work_orders')->where('id', $orphan->id)->delete();
    }

    public function down(): void
    {
        // One-way data cleanup; nothing to restore.
    }
};
