<?php

namespace Tests\Feature;

use App\Console\Commands\FollowUpTenantVendorContact;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\ServiceSchedule;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\TenantAppointmentNotificationService;
use App\Services\TenantEasyFixService;
use App\Services\TenantPortalLinkService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Every automated tenant message must carry the no-login portal link, and every
 * one of them must reuse the same token so a tenant never juggles two links for
 * the same work order.
 */
class TenantPortalLinkInAutomationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.tenant_assignment_sms' => true,
            'services.twilio.tenant_vendor_followup_sms' => true,
            'services.twilio.tenant_schedule_sms' => true,
            'services.twilio.tenant_schedule_followup_sms' => true,
            'services.twilio.maintenance_from' => '+12813787957',
            'services.twilio.maintenance_number' => '+12813787957',
        ]);

        // Long-past fresh-start epoch so the vendor-contact follow-up treats
        // these fixtures as post-epoch assignments and actually sends.
        AppSetting::putValue(
            FollowUpTenantVendorContact::EPOCH_KEY,
            now()->subYear()->toDateTimeString(),
        );

        Queue::fake();
    }

    private function makeTenant(): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => '5125559999',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * A vendor with no email (so the email step is skipped) and no user phone
     * (so no vendor SMS fires), isolating the tenant message under test.
     */
    private function makeVendor(string $name = 'Reliable Plumbing'): Vendor
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['phone' => null]);

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => null,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Run the vendor-assignment job with its PDF and PropertyWare dependencies
     * stubbed out, exactly as VendorAssignmentTenantNotificationTest does.
     */
    private function runAssignment(WorkOrder $workOrder, Vendor $vendor): void
    {
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-'.$vendor->id]);

        $pdf = Mockery::mock(WorkOrderInformationPdf::class);
        $pdf->shouldReceive('render')->andReturn('PDF-BYTES');

        $propertyWare = Mockery::mock(PropertyWareService::class);
        $propertyWare->shouldReceive('uploadWorkOrderPdf')->andReturnFalse();

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))->handle($pdf, $propertyWare);
    }

    private function makeWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43900,
            'tenant_id' => $this->makeTenant()->id,
        ]);
    }

    private function generalToken(WorkOrder $workOrder): ?TenantUploadToken
    {
        return TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->first();
    }

    /**
     * The newest tenant-thread message for this work order.
     */
    private function lastTenantMessage(WorkOrder $workOrder): string
    {
        return (string) Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->latest('id')
            ->firstOrFail()
            ->message;
    }

    private function assertCarriesPortalLink(WorkOrder $workOrder): void
    {
        $token = $this->generalToken($workOrder);

        $this->assertNotNull($token, 'The automation should have issued a general portal token.');
        $this->assertStringContainsString($token->token, $this->lastTenantMessage($workOrder));
    }

    public function test_the_vendor_assignment_text_carries_the_link(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->runAssignment($workOrder, $this->makeVendor());

        $this->assertCarriesPortalLink($workOrder);
    }

    public function test_the_vendor_contact_followup_carries_the_link(): void
    {
        $workOrder = $this->makeWorkOrder();
        $vendor = $this->makeVendor();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-1']);

        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->update(['created_at' => now()->subDay()]);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertCarriesPortalLink($workOrder);
    }

    public function test_the_appointment_confirmation_carries_the_link(): void
    {
        $workOrder = $this->makeWorkOrder();

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(3)->setTime(9, 0),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ]);

        app(TenantAppointmentNotificationService::class)->notify($schedule);

        $this->assertCarriesPortalLink($workOrder);
    }

    public function test_the_schedule_followup_carries_the_link(): void
    {
        $workOrder = $this->makeWorkOrder();

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(3)->setTime(9, 0),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ]);

        DB::table('service_schedules')
            ->where('id', $schedule->id)
            ->update(['created_at' => now()->subDay()]);

        // The follow-up only chases work orders that already hold a token.
        app(TenantPortalLinkService::class)->tokenFor($workOrder);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        $this->assertCarriesPortalLink($workOrder);
    }

    public function test_every_automation_reuses_the_same_token(): void
    {
        $workOrder = $this->makeWorkOrder();
        $vendor = $this->makeVendor();

        $this->runAssignment($workOrder, $vendor);

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(3)->setTime(9, 0),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        app(TenantAppointmentNotificationService::class)->notify($schedule);

        // One general token, no matter how many automations have fired.
        $this->assertSame(1, TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->count());

        $token = $this->generalToken($workOrder);

        $messages = Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->pluck('message');

        $this->assertGreaterThanOrEqual(2, $messages->count());

        foreach ($messages as $message) {
            $this->assertStringContainsString($token->token, $message);
        }
    }

    public function test_the_link_line_is_omitted_rather_than_losing_the_message(): void
    {
        $workOrder = $this->makeWorkOrder();

        // A link service that cannot issue a token.
        $this->app->bind(TenantPortalLinkService::class, function () {
            return new class(app(TenantEasyFixService::class)) extends TenantPortalLinkService
            {
                public function link(WorkOrder $workOrder, string $purpose = TenantUploadToken::PURPOSE_WORK_ORDER): ?string
                {
                    return null;
                }
            };
        });

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(3)->setTime(9, 0),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ]);

        app(TenantAppointmentNotificationService::class)->notify($schedule);

        $message = $this->lastTenantMessage($workOrder);

        // The message still went out, just without the link line.
        $this->assertStringContainsString('Reliable Plumbing', $message);
        $this->assertStringNotContainsString('no login needed', $message);
    }
}
