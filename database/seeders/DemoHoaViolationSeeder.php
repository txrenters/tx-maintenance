<?php

namespace Database\Seeders;

use App\Models\Attachments;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HoaViolationIntakeService;
use App\Services\TenantPortalLinkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local-only helper for testing the HOA Violations feature by hand.
 *
 * Sets up:
 *   1. A demo property (so it shows up in the "Upload HOA Notice" property
 *      dropdown) with a tenant and an owner that carry real-looking contact
 *      details.
 *   2. A sample HOA notice PDF written to the public disk — download it and
 *      re-upload it on the page to exercise the live intake path end-to-end.
 *   3. Three ready-made HOA work orders, one in each state the page renders:
 *        - "Waiting on tenant"     — fresh notice, deadline 5 business days out.
 *        - "Overdue — needs vendor" — deadline passed, escalation already flagged.
 *        - "Confirmed"             — corrected, before/after photos, email sent.
 *
 * Nothing here has a PropertyWare id, so no real work order is ever touched.
 */
class DemoHoaViolationSeeder extends Seeder
{
    private const DEMO_BUILDING_ID = 990500;

    private const PROPERTY_NAME = 'DEMO | 123 HOA Demo Dr';

    public function run(): void
    {
        $status = ServiceStatus::query()->firstWhere('name', HoaViolationIntakeService::EASY_FIX_STATUS)
            ?? ServiceStatus::query()->create([
                'name' => HoaViolationIntakeService::EASY_FIX_STATUS,
                'description' => HoaViolationIntakeService::EASY_FIX_STATUS,
            ]);

        $building = Building::query()->updateOrCreate(
            ['propertyware_id' => self::DEMO_BUILDING_ID],
            [
                'name' => self::PROPERTY_NAME,
                'address' => '123 HOA Demo Dr, Houston, TX 77001',
                'portfolio_id' => self::DEMO_BUILDING_ID,
                'active' => true,
            ]
        );

        $tenantUser = User::query()->updateOrCreate(
            ['email' => 'demo.tenant@example.com'],
            [
                'name' => 'Dana Tenant',
                'password' => bcrypt('password'),
            ]
        );

        $tenant = Tenants::query()->updateOrCreate(
            ['email' => 'demo.tenant@example.com'],
            [
                'first_name' => 'Dana',
                'last_name' => 'Tenant',
                'mobile_phone' => '5125550123',
                'address' => '123 HOA Demo Dr',
                'city' => 'Houston',
                'state' => 'TX',
                'zip' => '77001',
                'user_id' => $tenantUser->id,
            ]
        );

        $ownerUser = User::query()->updateOrCreate(
            ['email' => 'demo.owner@example.com'],
            [
                'name' => 'Olivia Owner',
                'password' => bcrypt('password'),
            ]
        );

        $owner = Owner::query()->updateOrCreate(
            ['propertyware_id' => self::DEMO_BUILDING_ID + 1],
            [
                'first_name' => 'Olivia',
                'last_name' => 'Owner',
                'name' => 'Olivia Owner',
                'email' => 'demo.owner@example.com',
                'mobile' => '5125550456',
                'percentage_ownership' => 100,
                'user_id' => $ownerUser->id,
            ]
        );

        $noticePdfPath = $this->writeSampleNoticePdf();

        // 1) Waiting on tenant — fresh notice, deadline in the future.
        $this->makeWorkOrder(
            workOrderNo: 99500,
            description: "HOA violation notice received.\n\nItems to correct:\n- Overgrown grass in the front yard exceeding 8 inches.\n- Trash bins left visible from the street.\n\nNotice issued by: Demo Oaks HOA",
            building: $building,
            status: $status,
            tenant: $tenant,
            owner: $owner,
            noticePdfPath: $noticePdfPath,
            noticeDate: now()->subWeekday(),
            deadlineAt: now()->addWeekdays(4)->endOfDay(),
            completedAt: null,
            escalationFlaggedAt: null,
            confirmationSentAt: null,
            withPhotos: false,
        );

        // 2) Overdue — deadline passed, tenant never uploaded, staff flagged.
        $overdue = $this->makeWorkOrder(
            workOrderNo: 99501,
            description: "HOA violation notice received.\n\nItems to correct:\n- Fence panel on the east side is broken and needs repair.\n\nNotice issued by: Demo Oaks HOA",
            building: $building,
            status: $status,
            tenant: $tenant,
            owner: $owner,
            noticePdfPath: $noticePdfPath,
            noticeDate: now()->subWeekdays(9),
            deadlineAt: now()->subWeekdays(2)->endOfDay(),
            completedAt: null,
            escalationFlaggedAt: now()->subWeekdays(2),
            confirmationSentAt: null,
            withPhotos: false,
        );
        $this->logEscalation($overdue);

        // 3) Confirmed — corrected with before/after photos, email already sent.
        $this->makeWorkOrder(
            workOrderNo: 99502,
            description: "HOA violation notice received.\n\nItems to correct:\n- Mailbox post is leaning and needs to be reset.\n\nNotice issued by: Demo Oaks HOA",
            building: $building,
            status: $status,
            tenant: $tenant,
            owner: $owner,
            noticePdfPath: $noticePdfPath,
            noticeDate: now()->subWeekdays(6),
            deadlineAt: now()->subWeekday()->endOfDay(),
            completedAt: now()->subDay(),
            escalationFlaggedAt: null,
            confirmationSentAt: now()->subDay(),
            withPhotos: true,
        );

        // Properties matching the real sample notice's two addresses, so the
        // uploaded PDF/photo auto-matches on the review screen. Each carries a
        // tenant + owner (via a prior work order) so the review shows contacts.
        $this->seedRealNoticeProperty(990510, '10107 Mariposa Green Ct, Houston, TX 77070', 'Chuang', 'Yang', $status);
        $this->seedRealNoticeProperty(990512, '2803 Briar Breeze Dr, Houston, TX 77014', 'Brandon', 'Alexander', $status);
        $this->seedRealNoticeProperty(990520, '9931 Chimney Swift Ln, Houston, TX 77064', 'Steven', 'Del Angel', $status);
        $this->seedRealNoticeProperty(990522, '10542 Paula Bluff Ln, Cypress, TX 77433', 'Guillian', 'Qiu', $status);
        $this->seedRealNoticeProperty(990524, '19323 Hays Spring Dr, Cypress, TX 77433', 'Zhongguo', 'Feng', $status);
        $this->seedRealNoticeProperty(990526, '19319 Hays Spring Dr, Cypress, TX 77433', 'Zhaohui', 'Fu', $status);
        $this->seedRealNoticeProperty(990528, '17007 Maravillas Cove Dr, Cypress, TX 77433', 'Xingcai', 'Wu', $status);

        // Mock tenant SMS thread on #99500 so the exact canned initial +
        // reminder wording can be reviewed in the Tenant Conversation tab.
        $this->seedTenantConversation(99500);

        $this->command?->info('Seeded HOA violations demo:');
        $this->command?->info("  Property in dropdown: {$building->name} (id {$building->propertyware_id})");
        $this->command?->info('  Sample notice PDF:   '.Storage::disk('public')->path($noticePdfPath));
        $this->command?->info('  Public URL:          '.asset('storage/'.$noticePdfPath));
        $this->command?->info('  Work orders: #99500 waiting, #99501 overdue, #99502 confirmed.');
        $this->command?->info('  Real-notice properties: 10107 Mariposa Green Ct, 2803 Briar Breeze Dr (upload your PDF to auto-match).');
    }

    /**
     * Seed a mock tenant SMS thread on an HOA work order so the exact canned
     * wording (initial link + daily reminders, plus a tenant reply) shows in
     * the Tenant Conversation tab for review. The text mirrors
     * TenantPortalLinkService's HOA messages.
     */
    private function seedTenantConversation(int $workOrderNo): void
    {
        $workOrder = WorkOrder::query()->where('work_order_no', $workOrderNo)->first();

        if (! $workOrder) {
            return;
        }

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->first();

        if (! $token) {
            return;
        }

        $tenant = $workOrder->requested_by;
        $name = trim((string) ($tenant?->first_name ?? '')) ?: 'there';
        $url = route('tenant.portal.show', $token->token);
        $ref = $workOrder->work_order_no ?? $workOrder->id;

        $maintenanceNumber = env('MAINTENANC_TWILIO_PHONE_NUMBER', '+12813787957');
        $tenantNumber = $tenant?->mobile_phone ?? '+15125550123';

        // Reuse the exact production summary logic so the demo thread shows the
        // violation the same way a real tenant text would.
        $summary = TenantPortalLinkService::violationSummaryFromDescription($workOrder->description);

        $initial = "Hi {$name}, this is TexasRenters.com Maintenance. We received an HOA notice for your home (WO#{$ref})"
            .($summary ? " regarding the following: {$summary}" : ' listing a few items that need a little attention')
            .'. Whenever you have a chance, we\'d truly appreciate it if you could take care of'
            .($summary ? ' it' : ' them').' and send us a quick photo as proof.'
            ." Here is your secure link — no login needed: {$url}. Thank you so much for your help!\n(Ref: WO#{$ref})";

        // Show the real reminder rotation so the demo thread demonstrates that
        // each follow-up is phrased differently (not the same bot line daily).
        $reminders = TenantPortalLinkService::hoaReminderVariants($name, $ref, $summary, $url);

        $thread = [
            ['message' => $initial, 'from' => $maintenanceNumber, 'to' => $tenantNumber, 'at' => now()->subDays(2)],
            ['message' => "Got it, thank you. I'll take care of it this week.", 'from' => $tenantNumber, 'to' => $maintenanceNumber, 'at' => now()->subDays(2)->addHours(3)],
            ['message' => $reminders[1]."\n(Ref: WO#{$ref})", 'from' => $maintenanceNumber, 'to' => $tenantNumber, 'at' => now()->subDay()],
            ['message' => $reminders[2]."\n(Ref: WO#{$ref})", 'from' => $maintenanceNumber, 'to' => $tenantNumber, 'at' => now()],
        ];

        // Clear any previous demo thread so re-seeding stays clean.
        Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->delete();

        foreach ($thread as $entry) {
            Conversation::query()->create([
                'work_order_id' => $workOrder->id,
                'conversation_type' => 'tenant',
                'message' => $entry['message'],
                'sender_number' => $entry['from'],
                'receiver_number' => $entry['to'],
                'twilio_status' => 'delivered',
                'created_at' => $entry['at'],
                'updated_at' => $entry['at'],
            ]);
        }
    }

    /**
     * Seed one property that matches an address on the real sample notice, with
     * a tenant + owner linked through a prior (closed) work order so the HOA
     * review screen can show who is on record for it.
     */
    private function seedRealNoticeProperty(int $buildingId, string $address, string $tenantFirst, string $tenantLast, ServiceStatus $status): void
    {
        $name = 'DEMO | '.trim(explode(',', $address)[0]);
        $slug = Str::of($tenantFirst.$tenantLast)->lower()->toString();

        $building = Building::query()->updateOrCreate(
            ['propertyware_id' => $buildingId],
            ['name' => $name, 'address' => $address, 'portfolio_id' => $buildingId, 'active' => true],
        );

        $tenantUser = User::query()->updateOrCreate(
            ['email' => "demo.{$slug}@example.com"],
            ['name' => $tenantFirst.' '.$tenantLast, 'password' => bcrypt('password')],
        );

        $tenant = Tenants::query()->updateOrCreate(
            ['email' => "demo.{$slug}@example.com"],
            [
                'first_name' => $tenantFirst,
                'last_name' => $tenantLast,
                'mobile_phone' => '5125559000',
                'address' => trim(explode(',', $address)[0]),
                'city' => 'Houston',
                'state' => 'TX',
                'zip' => '77070',
                'user_id' => $tenantUser->id,
            ],
        );

        $ownerUser = User::query()->updateOrCreate(
            ['email' => "demo.owner.{$slug}@example.com"],
            ['name' => $tenantFirst.' Owner', 'password' => bcrypt('password')],
        );

        $owner = Owner::query()->updateOrCreate(
            ['propertyware_id' => $buildingId + 1],
            [
                'first_name' => $tenantFirst,
                'last_name' => 'Owner',
                'name' => $tenantFirst.' Owner',
                'email' => "demo.owner.{$slug}@example.com",
                'mobile' => '5125559001',
                'percentage_ownership' => 100,
                'user_id' => $ownerUser->id,
            ],
        );

        // A prior closed work order establishes the tenant/owner linkage that
        // the review screen reads (property contacts come from the latest WO).
        $priorWorkOrder = WorkOrder::query()->updateOrCreate(
            ['work_order_no' => $buildingId],
            [
                'description' => 'Prior maintenance visit (demo linkage).',
                'category' => 'Maintenance',
                'location' => $name,
                'building_id' => $building->propertyware_id,
                'portfolio_id' => $building->portfolio_id,
                'status' => 'Closed',
                'service_status_id' => $status->id,
                'tenant_id' => $tenant->id,
                'created_date' => now()->subMonths(3),
            ],
        );

        $priorWorkOrder->owners()->syncWithoutDetaching([$owner->id]);
    }

    private function makeWorkOrder(
        int $workOrderNo,
        string $description,
        Building $building,
        ServiceStatus $status,
        Tenants $tenant,
        Owner $owner,
        string $noticePdfPath,
        Carbon $noticeDate,
        Carbon $deadlineAt,
        ?Carbon $completedAt,
        ?Carbon $escalationFlaggedAt,
        ?Carbon $confirmationSentAt,
        bool $withPhotos,
    ): WorkOrder {
        $workOrder = WorkOrder::query()->updateOrCreate(
            ['work_order_no' => $workOrderNo],
            [
                'description' => $description,
                'category' => config('services.hoa.pw_category'),
                'type' => config('services.hoa.pw_type'),
                'location' => $building->name,
                'building_id' => $building->propertyware_id,
                'portfolio_id' => $building->portfolio_id,
                'status' => 'Open',
                'service_status_id' => $status->id,
                'local_status' => 'Created',
                'tenant_id' => $tenant->id,
                'created_date' => $noticeDate->copy(),
            ]
        );

        // Link the owner (drives the corrected-confirmation email recipients).
        $workOrder->owners()->syncWithoutDetaching([$owner->id]);

        // Attach the notice PDF (like the real intake does).
        Attachments::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id, 'title' => 'HOA violation notice'],
            [
                'filename' => $noticePdfPath,
                'filetype' => 'application/pdf',
                'type' => 'attachment',
                'user_id' => $tenant->user_id,
                'pw_file_name' => 'HOA Notice - WO'.$workOrderNo.'.pdf',
                'created_at' => $noticeDate->copy(),
            ]
        );

        if ($withPhotos) {
            $this->attachDemoPhotos($workOrder, $tenant);
        }

        TenantUploadToken::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id, 'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION],
            [
                'token' => TenantUploadToken::generateUniqueToken(),
                'hoa_notice_date' => $noticeDate->toDateString(),
                'hoa_deadline_at' => $deadlineAt,
                'escalation_flagged_at' => $escalationFlaggedAt,
                'confirmation_sent_at' => $confirmationSentAt,
                'completed_at' => $completedAt,
                'last_notified_at' => $noticeDate->copy(),
            ]
        );

        return $workOrder;
    }

    private function attachDemoPhotos(WorkOrder $workOrder, Tenants $tenant): void
    {
        $before = $this->writeSamplePng('demo-hoa/before-'.$workOrder->id.'.png', [220, 53, 69]);
        $after = $this->writeSamplePng('demo-hoa/after-'.$workOrder->id.'.png', [40, 167, 69]);

        Attachments::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id, 'title' => 'Tenant photo - WO#'.$workOrder->work_order_no],
            [
                'filename' => $before,
                'filetype' => 'image/png',
                'type' => 'before',
                'user_id' => $tenant->user_id,
                'uploaded_via_tenant_portal' => true,
                'is_publish_to_owner_portal' => true,
                'is_publish_to_tenant_portal' => true,
                'created_at' => now()->subDay(),
            ]
        );

        Attachments::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id, 'title' => 'Corrected photo - WO#'.$workOrder->work_order_no],
            [
                'filename' => $after,
                'filetype' => 'image/png',
                'type' => 'after',
                'user_id' => $tenant->user_id,
                'is_publish_to_owner_portal' => true,
                'created_at' => now()->subDay(),
            ]
        );
    }

    private function logEscalation(WorkOrder $workOrder): void
    {
        if (! DB::getSchemaBuilder()->hasTable('activity_log')) {
            return;
        }

        DB::table('activity_log')->updateOrInsert(
            [
                'log_name' => 'hoa_violation_overdue',
                'subject_type' => WorkOrder::class,
                'subject_id' => $workOrder->id,
            ],
            [
                'description' => 'HOA violation deadline passed without tenant completion — assign a vendor.',
                'created_at' => now()->subWeekdays(2),
                'updated_at' => now()->subWeekdays(2),
            ]
        );
    }

    private function writeSampleNoticePdf(): string
    {
        $path = 'demo-hoa/HOA-Violation-Notice.pdf';

        $body = "Demo Oaks Homeowners Association\n"
            ."NOTICE OF VIOLATION\n\n"
            ."Property: 123 HOA Demo Dr, Houston, TX 77001\n"
            ."Date: this is a sample notice for local testing\n\n"
            ."The following violations were observed and must be corrected:\n"
            ."- Overgrown grass in the front yard exceeding 8 inches.\n"
            ."- Trash bins left visible from the street.\n\n"
            .'Please correct within the time allowed by the association rules.';

        Storage::disk('public')->put($path, $this->buildPdf($body));

        return $path;
    }

    /**
     * Build a minimal, valid single-page PDF with the given text so the file
     * opens in any viewer and can be re-uploaded through the intake page.
     */
    private function buildPdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $lines = explode("\n", $escaped);

        $content = "BT\n/F1 12 Tf\n1 0 0 1 60 760 Tm\n14 TL\n";
        foreach ($lines as $line) {
            $content .= '('.$line.") Tj\nT*\n";
        }
        $content .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function writeSamplePng(string $path, array $rgb): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(400, 300);
            $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
            imagefill($image, 0, 0, $color);
            ob_start();
            imagepng($image);
            $binary = (string) ob_get_clean();
            imagedestroy($image);
        } else {
            // Fallback: a 1x1 PNG of the requested color.
            $binary = $this->onePixelPng($rgb);
        }

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    private function onePixelPng(array $rgb): string
    {
        $signature = "\x89PNG\r\n\x1a\n";

        $ihdr = pack('N2C5', 1, 1, 8, 2, 0, 0, 0);
        $chunks = $this->pngChunk('IHDR', $ihdr);

        $raw = "\x00".chr($rgb[0]).chr($rgb[1]).chr($rgb[2]);
        $chunks .= $this->pngChunk('IDAT', gzcompress($raw));
        $chunks .= $this->pngChunk('IEND', '');

        return $signature.$chunks;
    }

    private function pngChunk(string $type, string $data): string
    {
        $crc = crc32($type.$data);

        return pack('N', strlen($data)).$type.$data.pack('N', $crc);
    }
}
