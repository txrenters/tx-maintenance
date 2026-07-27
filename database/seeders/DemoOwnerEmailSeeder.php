<?php

namespace Database\Seeders;

use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use Illuminate\Database\Seeder;

/**
 * Local-preview data for the owner Email History viewer: a demo owner with a
 * branded HTML vendor-assignment email, a plain-text schedule note, and an
 * inbound reply, so every branch of the shared EmailViewerDialog renders.
 *
 * Run with: php artisan db:seed --class=DemoOwnerEmailSeeder
 */
class DemoOwnerEmailSeeder extends Seeder
{
    public function run(): void
    {
        $owner = Owner::query()->firstOrCreate(
            ['email' => 'demo.owner.brandonalexander@example.com'],
            [
                'first_name' => 'Brandon',
                'last_name' => 'Alexander',
                'name' => 'Brandon Owner',
                'status' => 'Active',
            ],
        );

        // Idempotent: clear any previously-seeded demo emails for this owner so
        // re-running never piles up duplicates.
        OwnerEmailNotification::query()->where('owner_id', $owner->id)->delete();

        // Every owner email needs a work order (FK is not nullable); reuse the
        // owner's own if seeded elsewhere, otherwise the factory makes one.
        $workOrder = $owner->work_orders()->latest('created_date')->first();
        $workOrderId = $workOrder?->id;
        $workOrderNo = $workOrder?->work_order_no ?? '43466';

        $assignmentHtml = <<<HTML
<p>Hello Brandon Owner,</p>
<p>We have assigned <strong>Jimmie Gendke SFA</strong> (+19362544960) to handle the repairs at 1514B Creekside Ln under Work Order #{$workOrderNo}.</p>
<p>The vendor will contact the tenant directly to coordinate and schedule the appointment.</p>
<p>Thank you,<br>The TexasRenters.com Property Management Team</p>
HTML;

        $this->makeEmail($owner->id, $workOrderId, [
            'type' => 'vendor_assignment',
            'direction' => 'outbound',
            'subject' => "Vendor Assigned - Work Order #{$workOrderNo} [TXO-{$workOrderNo}-107]",
            'body_html' => $assignmentHtml,
            'body_text' => trim(strip_tags(str_replace(['</p>', '<br>'], ["\n\n", "\n"], $assignmentHtml))),
            'from_email' => 'workorders@texasrenters.com',
            'to_email' => 'demo.owner.brandonalexander@example.com',
            'cc' => ['mc@texasrenters.com'],
            'sent_at' => now()->subDays(2),
        ]);

        $this->makeEmail($owner->id, $workOrderId, [
            'type' => 'schedule_update',
            'direction' => 'outbound',
            'subject' => "Appointment Scheduled - Work Order #{$workOrderNo}",
            'body_html' => '',
            'body_text' => "Hello Brandon Owner,\n\nThe vendor has scheduled the repair for this Thursday between 9 AM and 12 PM. The tenant has confirmed availability.\n\nWe will let you know once the work is completed.\n\nThank you,\nTexasRenters.com Maintenance",
            'from_email' => 'workorders@texasrenters.com',
            'to_email' => 'demo.owner.brandonalexander@example.com',
            'cc' => [],
            'sent_at' => now()->subDay(),
        ]);

        $this->makeEmail($owner->id, $workOrderId, [
            'type' => 'owner_reply',
            'direction' => 'inbound',
            'subject' => "Re: Vendor Assigned - Work Order #{$workOrderNo}",
            'body_html' => '',
            'body_text' => "Thanks for the update. Please make sure the vendor takes before and after photos so I have them for my records.\n\nBrandon",
            'from_email' => 'demo.owner.brandonalexander@example.com',
            'to_email' => 'workorders@texasrenters.com',
            'cc' => [],
            'sent_at' => now()->subHours(4),
        ]);

        $this->command?->info("Demo owner id {$owner->id} seeded — open /owners/{$owner->id} and the Email History tab.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEmail(int $ownerId, ?int $workOrderId, array $attributes): void
    {
        $attributes['owner_id'] = $ownerId;

        if ($workOrderId !== null) {
            $attributes['work_order_id'] = $workOrderId;
        }

        OwnerEmailNotification::factory()->create($attributes);
    }
}
