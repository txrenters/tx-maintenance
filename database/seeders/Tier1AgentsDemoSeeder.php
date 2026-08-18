<?php

namespace Database\Seeders;

use App\Models\AiInsight;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Local demo data for the four read-only AI agents (NOT part of the normal
 * seed chain — run by hand):
 *
 *   php artisan db:seed --class=Tier1AgentsDemoSeeder
 *
 * Creates clearly-marked demo work orders ("DEMO: ...") with message threads
 * for each triage intent, a mismatched vendor "after" photo, and a stale
 * work order for the open-over-30 Analyze button. Re-running first removes
 * ONLY rows from previous runs of this seeder (matched by the DEMO: prefix).
 *
 * The seeder writes data only; run `php artisan inbox:triage-messages` (and
 * let the photo-review job run) afterwards so the verdicts come from the
 * real AI pipeline, not canned rows.
 */
class Tier1AgentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->cleanupPreviousRuns();

        $user = User::query()->first() ?? User::factory()->create();

        // 1) Reschedule request + concrete time — should produce a chip AND a
        // schedule suggestion on the Service Schedule tab.
        $reschedule = $this->workOrder('DEMO: Bedroom ceiling leak — water stain spreading', 990001);
        $this->message($reschedule, false, 'Hi, we received your request about the ceiling leak. Our plumber will reach out to schedule the repair.', 3);
        $this->message($reschedule, true, 'Can we move the visit to Tuesday after 2pm? I work mornings so nobody is home before noon.', 2);

        // 2) Complaint.
        $complaint = $this->workOrder('DEMO: AC not cooling — blowing warm air', 990002);
        $this->message($complaint, false, 'The AC tech is scheduled for Friday morning between 9 and 11.', 4);
        $this->message($complaint, true, 'Nobody showed up again and it is 95 degrees in here. This is the second time I have waited all morning!', 1);

        // 3) Access issue.
        $access = $this->workOrder('DEMO: Garbage disposal jammed', 990003);
        $this->message($access, true, 'The gate code you gave the vendor does not work and the lockbox by the front door is missing.', 1);

        // 4) Job done.
        $done = $this->workOrder('DEMO: Fence gate repair', 990004);
        $this->message($done, false, 'Acme Fencing should be finishing up the gate today.', 2);
        $this->message($done, true, 'The gate is fixed now and closes properly. Looks great!', 1);

        // 5) Vendor "after" photo that does not match the reported issue —
        // feeds the completion-photo review (run by the pipeline, not here).
        $photoWorkOrder = $this->workOrder('DEMO: Bedroom ceiling leak — drywall repair after plumbing fix', 990005);
        $this->demoAfterPhoto($photoWorkOrder, $user);

        // 6) Stale work order for the open-over-30 report's Analyze button.
        $stale = $this->workOrder('DEMO: Water heater replacement — no hot water', 990006, now()->subDays(58));
        $vendor = $this->demoVendor();
        $stale->vendors()->attach($vendor->id, ['access_token' => 'demo-'.uniqid()]);
        WorkOrderNotes::query()->create([
            'subject' => 'Parts approval',
            'body' => 'Acme Plumbing quoted $1,450 for the replacement heater and asked for owner approval before ordering the unit.',
            'is_private' => false,
            'work_order_id' => $stale->id,
            'user_id' => $user->id,
        ]);
        $this->message($stale, false, 'Acme Plumbing needs approval on the part cost before they can order the water heater.', 46);
        $this->message($stale, true, 'The owner approved the cost, please tell them to proceed.', 45);

        $this->command?->info('Demo rows created (work orders 990001-990006).');
        $this->command?->info('Now run: php artisan inbox:triage-messages');
    }

    private function cleanupPreviousRuns(): void
    {
        $ids = WorkOrder::withoutGlobalScopes()
            ->where('description', 'like', 'DEMO:%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        AiInsight::query()->whereIn('work_order_id', $ids)->delete();
        Conversation::withoutGlobalScopes()->whereIn('work_order_id', $ids)->delete();
        WorkOrderNotes::query()->whereIn('work_order_id', $ids)->delete();
        Attachments::withoutGlobalScopes()->whereIn('work_order_id', $ids)->delete();
        WorkOrder::withoutGlobalScopes()->whereIn('id', $ids)->each(function (WorkOrder $workOrder) {
            $workOrder->vendors()->detach();
            $workOrder->delete();
        });
    }

    private function workOrder(string $description, int $number, $createdDate = null): WorkOrder
    {
        return WorkOrder::factory()->create([
            'description' => $description,
            'work_order_no' => $number,
            'created_date' => $createdDate ?? now()->subDays(5),
        ]);
    }

    private function message(WorkOrder $workOrder, bool $inbound, string $text, int $daysAgo): Conversation
    {
        return Conversation::query()->create([
            'message' => $text,
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? '+15125550001' : '+15125559999',
            'receiver_number' => $inbound ? '+15125559999' : '+15125550001',
            'work_order_id' => $workOrder->id,
            'is_read' => ! $inbound,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * A generated JPEG that plainly shows a kitchen sink label — deliberately
     * unrelated to the ceiling-leak work order so the vision review has a
     * mismatch to catch.
     */
    private function demoAfterPhoto(WorkOrder $workOrder, User $user): Attachments
    {
        $image = imagecreatetruecolor(640, 480);
        $white = imagecolorallocate($image, 245, 245, 245);
        $gray = imagecolorallocate($image, 120, 120, 120);
        $dark = imagecolorallocate($image, 40, 40, 40);
        imagefill($image, 0, 0, $white);
        imagefilledrectangle($image, 120, 200, 520, 340, $gray);
        imagefilledellipse($image, 320, 270, 240, 90, $white);
        imagestring($image, 5, 210, 100, 'KITCHEN SINK - NEW FAUCET', $dark);
        imagestring($image, 4, 240, 400, 'Installed and sealed', $dark);

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = 'attachments/demo-after-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return Attachments::query()->create([
            'title' => 'After repair photo',
            'filename' => $path,
            'filetype' => 'image/jpeg',
            'type' => 'after',
            'work_order_id' => $workOrder->id,
            'user_id' => $user->id,
        ]);
    }

    private function demoVendor(): Vendor
    {
        return Vendor::query()->firstOrCreate(
            ['propertyware_id' => 'DEMO-VENDOR-1'],
            [
                'name' => 'DEMO Acme Plumbing',
                'vendor_type' => 'Plumbing',
                'is_active' => true,
                'user_id' => User::factory()->create()->id,
            ],
        );
    }
}
