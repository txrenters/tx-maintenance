<?php

namespace Tests\Feature;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\VendorPortalLinkService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A vendor who only reads their texts must still be able to open the job, so
 * every automated vendor text carries their magic link.
 */
class VendorPortalLinkInAutomationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.twilio.maintenance_number' => '+12813787957',
        ]);
        Cache::forget('microsoft.graph.token');
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::response([
                'id' => 'TEST_GRAPH_ID',
                'internetMessageId' => '<test@texasrenters.com>',
                'conversationId' => 'TEST_CONVERSATION_ID',
            ]),
            'api.propertyware.com/*' => Http::response(['id' => 987654321], 200),
        ]);
    }

    private function makeVendor(?string $phone = '2815550000'): Vendor
    {
        $user = User::factory()->create(['phone' => $phone]);

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => 'vendor'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);
    }

    private function vendorMessage(WorkOrder $workOrder): ?string
    {
        return Conversation::where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'vendor')
            ->value('message');
    }

    public function test_assignment_text_carries_the_vendor_portal_link(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43339]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-assignment']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $message = $this->vendorMessage($workOrder);

        $this->assertNotNull($message);
        $this->assertStringContainsString(route('vendor.portal.show', 'tok-assignment'), $message);
        $this->assertStringContainsString(VendorPortalLinkService::LINK_LEAD, $message);
        // The link sits above the sign-off, not after it.
        $this->assertStringEndsWith('— TX Maintenance Team', $message);
    }

    public function test_assignment_text_still_sends_when_no_token_exists(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43340]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => null]);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $message = $this->vendorMessage($workOrder);

        $this->assertNotNull($message);
        $this->assertStringContainsString('You have been assigned Work Order #43340.', $message);
        $this->assertStringNotContainsString(VendorPortalLinkService::LINK_LEAD, $message);
    }

    public function test_schedule_followup_text_carries_the_vendor_portal_link(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 43341]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-followup']);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $message = $this->vendorMessage($workOrder);

        $this->assertNotNull($message);
        $this->assertStringContainsString(route('vendor.portal.show', 'tok-followup'), $message);
        // The operations-approved copy is untouched; the link is appended to it.
        $this->assertStringContainsString('a service schedule has not yet been set', $message);
    }

    public function test_followup_mints_a_token_for_an_assignment_that_has_none(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 43342]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => null]);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $token = DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->value('access_token');

        $this->assertNotEmpty($token, 'A missing token should be minted so the link works.');
        $this->assertStringContainsString(
            route('vendor.portal.show', $token),
            (string) $this->vendorMessage($workOrder),
        );
    }

    public function test_link_service_returns_null_for_a_vendor_who_is_not_assigned(): void
    {
        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43343]);

        $this->assertNull(app(VendorPortalLinkService::class)->link($workOrder, $vendor));
    }
}
