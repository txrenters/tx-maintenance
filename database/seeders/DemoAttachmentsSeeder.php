<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local-only helper: seed a work order's Attachments tab with realistically
 * long file names — plus a PropertyWare thumbnail (THMP_) and a duplicate — so
 * the filename-wrapping fix and the thumbnail dedupe can be checked visually.
 */
class DemoAttachmentsSeeder extends Seeder
{
    public function run(): void
    {
        $workOrder = WorkOrder::query()
            ->where('status', 'Open')
            ->latest('id')
            ->first();

        if (! $workOrder) {
            $this->command?->warn('No open work order found to attach demo files to.');

            return;
        }

        $now = now()->format('Y-m-d H:i:s');

        $documentNames = [
            'Work Order Information.pdf',
            'Estimate_20260619_040304_Chimney_Repair_10002_Sally_Grove_Ct.pdf',
            'Invoice INV-4803_78 E Mistybreeze Cir_WO#43114.pdf',
            // Duplicate of the invoice above (should be collapsed by dedupe).
            'Invoice INV-4803_78 E Mistybreeze Cir_WO#43114.pdf',
            // PropertyWare-generated thumbnail (should be removed by dedupe / skipped on import).
            'THMP_Invoice INV-4803_78 E Mistybreeze Cir_WO#43114.pdf',
        ];

        // Clear any previous run for this work order so re-seeding stays clean.
        DB::table('work_order_documents')
            ->where('work_order_id', $workOrder->id)
            ->whereIn('file_name', $documentNames)
            ->delete();

        $rows = [];
        foreach ($documentNames as $index => $fileName) {
            $rows[] = [
                'propertyware_id' => 990000000 + $index,
                'description' => null,
                'file_type' => 'application/pdf',
                'file_name' => $fileName,
                'is_private' => false,
                'is_publish_to_owner_portal' => false,
                'is_publish_to_tenant_portal' => false,
                'work_order_id' => $workOrder->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('work_order_documents')->insert($rows);

        // A couple of uploaded attachments with long titles for the Attachments/Before sections.
        $user = User::query()->latest('id')->first();
        $attachmentTitles = [
            'Before_Photos_20260619_035442_Chimney_Front_Elevation.pdf',
            'After_Photos_20260624_191846_Chimney_Cap_Installed_Final.pdf',
        ];
        DB::table('attachments')
            ->where('work_order_id', $workOrder->id)
            ->whereIn('title', $attachmentTitles)
            ->delete();

        foreach ($attachmentTitles as $index => $title) {
            DB::table('attachments')->insert([
                'title' => $title,
                'filename' => $title,
                'filetype' => 'application/pdf',
                'type' => 'attachment',
                'work_order_id' => $workOrder->id,
                'user_id' => $user?->id,
                'is_private' => false,
                'is_publish_to_owner_portal' => false,
                'is_publish_to_tenant_portal' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command?->info("Seeded demo attachments/documents on work order #{$workOrder->work_order_no} (id {$workOrder->id}).");
    }
}
