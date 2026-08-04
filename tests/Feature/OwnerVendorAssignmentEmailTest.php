<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerVendorAssignmentEmail;
use App\Models\Owner;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use App\Services\OwnerWorkOrderEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class OwnerVendorAssignmentEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_sender_sanitizes_persists_attachment_and_sender(): void
    {
        Storage::fake('local');
        config(['services.microsoft.mailbox' => 'workorders@texasrenters.com']);
        [$workOrder, , $owner] = $this->records();
        $user = User::factory()->create();
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('sendMail')->once()->withArgs(fn ($to, $cc, $subject, $html, $files, $mailbox) => $to === 'other-owner@example.com'
            && $cc === []
            && str_contains($subject, '[TXO-4321-'.$owner->id.']')
            && ! str_contains($html, 'script')
            && $files[0]['contentBytes'] === base64_encode('estimate')
            && $mailbox === 'workorders@texasrenters.com')->andReturn([
                'graph_message_id' => 'MANUAL-G1', 'graph_conversation_id' => 'MANUAL-C1', 'internet_message_id' => '<manual-g1>',
            ]);
        $this->app->instance(MicrosoftGraphMailService::class, $graph);

        $notification = app(OwnerWorkOrderEmailSender::class)->send(
            owner: $owner,
            workOrder: $workOrder,
            to: 'other-owner@example.com',
            mailbox: 'workorders@texasrenters.com',
            subject: 'Repair update',
            html: '<p>Scheduled<script>bad()</script></p>',
            files: [['name' => 'estimate.pdf', 'contentType' => 'application/pdf', 'bytes' => 'estimate']],
            sentBy: $user,
        );

        $this->assertSame('manual', $notification->type);
        $this->assertSame([], $notification->cc);
        $this->assertSame($user->id, $notification->sent_by_user_id);
        $this->assertTrue($notification->has_attachments);
        $this->assertSame('estimate.pdf', $notification->attachments()->sole()->filename);
    }

    public function test_sender_uses_workorders_mailbox_cc_tag_and_is_idempotent(): void
    {
        config(['services.microsoft.mailbox' => 'workorders@texasrenters.com']);
        [$workOrder, $vendor, $owner] = $this->records();
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('sendMail')->once()->withArgs(fn ($to, $cc, $subject, $html, $files, $mailbox) => $to === $owner->email
            && $cc === ['mc@texasrenters.com', 'ofm@txhomemp.com']
            && str_contains($subject, '[TXO-4321-'.$owner->id.']')
            && $mailbox === 'workorders@texasrenters.com')->andReturn([
                'graph_message_id' => 'OWNER-G1', 'graph_conversation_id' => 'OWNER-C1', 'internet_message_id' => '<owner-g1>',
            ]);
        $this->app->instance(MicrosoftGraphMailService::class, $graph);
        $sender = app(OwnerWorkOrderEmailSender::class);

        $first = $sender->sendVendorAssignment($workOrder, $owner, $vendor, 'Vendor Assigned', '<p>Hello</p>');
        $second = $sender->sendVendorAssignment($workOrder, $owner, $vendor, 'Vendor Assigned', '<p>Hello</p>');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('owner_email_notifications', 1);
        $this->assertSame('OWNER-G1', $first->graph_message_id);
    }

    public function test_job_targets_primary_property_owner_even_when_sms_is_disabled(): void
    {
        config(['services.twilio.owner_assignment_sms' => false]);
        [$workOrder, $vendor, $owner] = $this->records();
        $manager = Owner::factory()->create(['email' => 'manager@example.com', 'percentage_ownership' => 0]);
        $workOrder->owners()->attach([$manager->id, $owner->id]);
        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->once()->withArgs(fn ($wo, $target, $assignedVendor) => $target->is($owner) && $assignedVendor->is($vendor));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);
    }

    public function test_job_skips_import_placeholder_email_and_owner_vendor(): void
    {
        [$workOrder, $vendor, $owner] = $this->records();
        $owner->update(['email' => '12345@texasrenter.com']);
        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldNotReceive('sendVendorAssignment');

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);

        $owner->update(['email' => 'owner@example.com']);
        $vendor->update(['name' => 'OWNER VENDOR']);
        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);
    }

    public function test_turnover_work_orders_still_email_the_owner_without_the_tenant_line(): void
    {
        [$workOrder, $vendor, $owner] = $this->records();
        $workOrder->owners()->attach($owner->id);

        // Turnover properties are vacant, so the owner still gets the
        // vendor-assignment email but without the "contact the tenant" line.
        $workOrder->update(['type' => 'Turnover']);

        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->twice()
            ->withArgs(fn ($wo, $target, $assignedVendor, $subject, $html) => ! str_contains($html, 'contact the tenant directly'));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);

        // Turnover carried as the category (PropertyWare is inconsistent about
        // which field holds it) behaves the same.
        $workOrder->update(['type' => 'Service Request', 'category' => 'Turnover']);
        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);
    }

    public function test_vacant_toggle_drops_the_tenant_line_but_still_emails_the_owner(): void
    {
        [$workOrder, $vendor, $owner] = $this->records();
        $workOrder->owners()->attach($owner->id);

        // The WOC's "Vacant" toggle: no tenant to contact, so the owner still
        // gets the vendor-assignment email but without the tenant line.
        $workOrder->update(['skip_automated_tasks' => true]);

        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->once()
            ->withArgs(fn ($wo, $target, $assignedVendor, $subject, $html) => ! str_contains($html, 'contact the tenant directly'));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);
    }

    public function test_occupied_work_order_keeps_the_tenant_line_in_the_email(): void
    {
        [$workOrder, $vendor, $owner] = $this->records();
        $workOrder->owners()->attach($owner->id);

        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->once()
            ->withArgs(fn ($wo, $target, $assignedVendor, $subject, $html) => str_contains($html, 'The vendor will contact the tenant directly'));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);
    }

    public function test_email_shows_the_work_order_description_and_hides_the_panel_when_blank(): void
    {
        [$workOrder, $vendor, $owner] = $this->records();
        $workOrder->owners()->attach($owner->id);
        $workOrder->update(['description' => "Kitchen faucet leaking\nWater under the sink"]);

        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->once()
            ->withArgs(fn ($wo, $target, $assignedVendor, $subject, $html) => str_contains($html, 'Request Details')
                && str_contains($html, 'Kitchen faucet leaking<br />')
                && str_contains($html, 'Water under the sink'));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);

        // Nothing on file: the panel disappears rather than rendering empty.
        $workOrder->update(['description' => '   ']);
        $blank = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $blank->shouldReceive('sendVendorAssignment')->once()
            ->withArgs(fn ($wo, $target, $assignedVendor, $subject, $html) => ! str_contains($html, 'Request Details'));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($blank);
    }

    public function test_email_escapes_html_in_the_description(): void
    {
        [$workOrder, $vendor, $owner] = $this->records();
        $workOrder->owners()->attach($owner->id);
        $workOrder->update(['description' => '<script>bad()</script> AC out']);

        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->once()
            ->withArgs(fn ($wo, $target, $assignedVendor, $subject, $html) => ! str_contains($html, '<script>')
                && str_contains($html, '&lt;script&gt;'));

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);
    }

    /** @return array{0: WorkOrder, 1: Vendor, 2: Owner} */
    private function records(): array
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4321]);
        $vendor = Vendor::query()->create(['propertyware_id' => uniqid('V-'), 'name' => 'Acme Plumbing', 'email' => 'vendor@example.com', 'is_active' => true, 'user_id' => User::factory()->create()->id]);
        $owner = Owner::factory()->create(['email' => 'owner@example.com', 'percentage_ownership' => 100]);

        return [$workOrder, $vendor, $owner];
    }
}
