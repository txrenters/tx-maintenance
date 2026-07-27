<?php

namespace Database\Seeders;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Local-preview data for the vendor Email History viewer: one demo vendor
 * with a realistic outbound service-request email (the real production
 * wording) and a plain-text inbound reply, so both viewer branches render.
 *
 * Run with: php artisan db:seed --class=DemoVendorEmailSeeder
 */
class DemoVendorEmailSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = Vendor::query()->firstOrCreate(
            ['name' => 'SDM Home Services LLC (Demo)'],
            [
                'propertyware_id' => 'DEMO-SDM-1',
                'email' => 'admin@sdmhomeservices.example',
                'vendor_type' => 'General Maintenance',
                'is_active' => true,
                'user_id' => User::factory()->create([
                    'name' => 'SDM Demo',
                    'email' => 'sdm-demo@example.com',
                ])->id,
            ],
        );

        $html = <<<'HTML'
<p>New Service Request<br>Work Order #43334</p>
<p>Dear SDM Home Services LLC,</p>
<p>Please see the attached service request.</p>
<p>Please be sure to contact the tenants within <strong>2 business days</strong> of receipt of this request to schedule a time for repairs. To avoid trip charges, we advise scheduling with the tenants via email so you have written proof they confirmed the appointment time. If written proof cannot be provided and tenants are not home at the scheduled time, we cannot charge trip charges. As always, please keep Texas Renters updated on all progress (scheduling, progress, estimates, etc.).</p>
<p>Please take <strong>before and after pictures of ALL repairs</strong>. Upload them through your maintenance dashboard upon completion, and title each photo with the property address. We cannot process an invoice for payment without photos.</p>
<p><a href="https://example.com/portal">Open Work Order &amp; Upload Photos</a> Manage this work order in your vendor portal</p>
<p>If you have any questions or issues reaching or communicating with the tenants, please contact us and let us know.</p>
<p><strong>NOTE TO OUR VENDORS:</strong> Texas Renters payment policy &mdash; invoices are paid on the <strong>1st and 15th</strong> of the month. Thank you.</p>
<p>Best regards,<br>The TexasRenters.com Property Management Team</p>
<hr>
<p>This is an automated email notification, please do not respond.</p>
<p><a href="https://texasrenters.com">TexasRenters.com</a> &middot; 5225 Katy Freeway, Ste 545, Houston, TX 77007</p>
HTML;

        EmailMessage::factory()->create([
            'vendor_id' => $vendor->id,
            'subject' => 'New Service Request - Work Order #43334 [TX-43334-3332]',
            'body_html' => $html,
            'body_text' => trim(strip_tags($html)),
            'to_email' => 'admin@sdmhomeservices.example',
            'cc' => ['mc@texasrenters.com', 'ofm@txhomemp.com'],
            'emailed_at' => now()->subHours(3),
        ]);

        EmailMessage::factory()->inbound()->create([
            'vendor_id' => $vendor->id,
            'subject' => 'Re: New Service Request - Work Order #43334 [TX-43334-3332]',
            'body_html' => null,
            'body_text' => "Hi,\nWe just left the property a few minutes ago. The capacitor was replaced and the system is cooling again.\nPhotos will be uploaded tonight.\n\nThanks,\nSDM",
            'from_email' => 'admin@sdmhomeservices.example',
            'to_email' => 'workorders@texasrenters.com',
            'cc' => [],
            'emailed_at' => now()->subHour(),
        ]);

        $this->command?->info("Demo vendor id {$vendor->id} seeded — open /vendors/{$vendor->id} and the Email History tab.");
    }
}
