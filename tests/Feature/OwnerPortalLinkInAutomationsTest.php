<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerVendorAssignmentEmail;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\OwnerAppointmentNotificationService;
use App\Services\OwnerServiceRequestNotificationService;
use App\Services\OwnerWorkOrderEmailSender;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The owner portal is only useful if its link rides along in the automated
 * messages the owner already receives. One test per automation, asserting the
 * link is embedded and that it resolves to that owner's own token.
 */
class OwnerPortalLinkInAutomationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::forget('microsoft.graph.token');
        config(['services.twilio.maintenance_number' => '+15550001111']);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::response([
                'id' => 'TEST_GRAPH_ID',
                'internetMessageId' => '<test@texasrenters.com>',
                'conversationId' => 'TEST_CONVERSATION_ID',
            ]),
            'api.propertyware.com/*' => Http::response(['id' => 'doc-1'], 200),
        ]);
    }

    private function makeOwner(string $phone = '7135030427'): Owner
    {
        return Owner::query()->create([
            'first_name' => 'Olivia',
            'last_name' => 'Owner',
            'name' => 'Olivia Owner',
            'email' => 'olivia@example.com',
            'mobile' => $phone,
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(Owner $owner): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        $building = Building::query()->create([
            'propertyware_id' => 8201,
            'name' => '6341 Del Monte Dr',
            'address' => '6341 Del Monte Dr',
            'portfolio_id' => 900,
        ]);

        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'dana@example.com',
            'mobile_phone' => '5125558888',
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 44100,
            'propertyware_id' => 4377411585,
            'building_id' => $building->propertyware_id,
            'tenant_id' => $tenant->id,
            'description' => 'Water heater is leaking in the garage',
        ]);

        $workOrder->owners()->attach($owner->id);

        return $workOrder;
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-9001',
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => 'vendor@example.com',
            'user_id' => User::factory()->create(['phone' => '3255550101'])->id,
        ]);
    }

    /**
     * The link the owner should have been sent, from their own token.
     */
    private function expectedLink(WorkOrder $workOrder, Owner $owner): string
    {
        $token = OwnerPortalToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('owner_id', $owner->id)
            ->firstOrFail();

        return route('owner.portal.show', $token->token);
    }

    public function test_the_intake_text_carries_the_owner_portal_link(): void
    {
        config(['services.twilio.owner_service_request_sms' => true]);
        Queue::fake();

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $confirmation = Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->orderBy('id')
            ->value('message');

        $this->assertStringContainsString('no login needed', $confirmation);
        $this->assertStringContainsString($this->expectedLink($workOrder, $owner), $confirmation);
    }

    public function test_the_vendor_assignment_text_carries_the_owner_portal_link(): void
    {
        config(['services.twilio.owner_assignment_sms' => true]);
        Bus::fake();
        Mail::fake();

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $vendor = $this->makeVendor();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $message = Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertStringContainsString($this->expectedLink($workOrder, $owner), $message);
    }

    public function test_the_vendor_assignment_email_carries_the_owner_portal_link(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $vendor = $this->makeVendor();

        $captured = null;

        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('sendVendorAssignment')->once()
            ->withArgs(function ($wo, $target, $assignedVendor, $subject, $html) use (&$captured) {
                $captured = $html;

                return true;
            });

        (new SendOwnerVendorAssignmentEmail($workOrder->id, $vendor->id))->handle($sender);

        $this->assertNotNull($captured);
        $this->assertStringContainsString($this->expectedLink($workOrder, $owner), $captured);
        $this->assertStringContainsString('no login needed', $captured);
    }

    public function test_the_appointment_text_carries_the_owner_portal_link(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        Queue::fake();

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $vendor = $this->makeVendor();

        $schedule = ServiceSchedule::query()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'title' => 'Repair visit',
            'scheduled_date' => now()->addDays(3),
        ]);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        $message = Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertStringContainsString($this->expectedLink($workOrder, $owner), $message);
    }

    public function test_every_automation_reuses_the_same_token_for_one_owner(): void
    {
        config([
            'services.twilio.owner_service_request_sms' => true,
            'services.twilio.owner_schedule_sms' => true,
        ]);
        Queue::fake();

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $vendor = $this->makeVendor();

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $schedule = ServiceSchedule::query()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'title' => 'Repair visit',
            'scheduled_date' => now()->addDays(3),
        ]);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        // One owner, one work order, one link across every message they get.
        $this->assertSame(1, OwnerPortalToken::query()->count());
    }

    public function test_each_owner_gets_their_own_link_in_the_same_automation(): void
    {
        config(['services.twilio.owner_service_request_sms' => true]);
        Queue::fake();

        $owner = $this->makeOwner('7135030427');
        $coOwner = $this->makeOwner('7135039999');
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(2, OwnerPortalToken::query()->count());

        // Each owner's own message carries their own token, never the other's.
        foreach ([$owner, $coOwner] as $recipient) {
            $message = Conversation::query()
                ->where('work_order_id', $workOrder->id)
                ->where('owner_id', $recipient->id)
                ->orderBy('id')
                ->value('message');

            $this->assertStringContainsString($this->expectedLink($workOrder, $recipient), $message);
        }
    }
}
