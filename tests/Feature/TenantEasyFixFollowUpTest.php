<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\PropertyWareService;
use App\Services\TenantEasyFixService;
use App\Services\TenantMessageFormatter;
use App\Services\TenantServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The check-ins after the easy-fix how-to text: the manual's "follow up with
 * the tenant within 1 day", one per weekday, three at most, each worded
 * differently, and stopped as soon as the tenant answers or the work order
 * moves on.
 */
class TenantEasyFixFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday morning, so "one weekday later" is always the next day.
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));

        config([
            'services.twilio.tenant_intake_sms' => true,
            'services.twilio.tenant_easy_fix_sms' => true,
            'services.twilio.tenant_portal_sms' => false,
            'services.twilio.maintenance_from' => '+12813787957',
        ]);

        $items = config('tenant_easy_fix.items');

        foreach ($items as $index => $item) {
            if ($item['key'] === 'disposal_jammed') {
                $items[$index]['video_url'] = 'https://youtu.be/disposal';
            }
        }

        config(['tenant_easy_fix.items' => $items]);

        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
        ServiceStatus::query()->firstOrCreate(['name' => TenantEasyFixService::EASY_FIX_STATUS], ['description' => 'easy fix']);

        $propertyWare = Mockery::mock(PropertyWareService::class);
        $propertyWare->shouldReceive('updateServiceStatus')->andReturn(true);
        $this->app->instance(PropertyWareService::class, $propertyWare);

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
     * A tenant-portal disposal request that has just been sent the how-to
     * text, and the easy-fix token that text opened.
     *
     * @return array{0: WorkOrder, 1: TenantUploadToken}
     */
    private function textedWorkOrder(): array
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43900,
            'source' => 'Tenant Portal',
            'propertyware_id' => 43900001,
            'lease_id' => 555001,
            'description' => 'Garbage disposal is humming but not turning',
            'category' => 'Garbage Disposal',
            'tenant_id' => $this->makeTenant()->id,
        ]);

        app(TenantServiceRequestNotificationService::class)->notify($workOrder);

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->firstOrFail();

        $this->assertSame(1, $token->notified_count);
        $this->assertCount(1, $this->tenantMessages($workOrder));

        return [$workOrder->fresh(), $token];
    }

    /**
     * @return array<int, Conversation>
     */
    private function tenantMessages(WorkOrder $workOrder): array
    {
        return Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function nextWeekday(): void
    {
        $this->travelTo(now()->addWeekday());
    }

    public function test_the_tenant_is_checked_on_one_weekday_after_the_how_to_and_the_wording_changes_each_day(): void
    {
        [$workOrder, $token] = $this->textedWorkOrder();

        // Same day: nothing yet.
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertCount(1, $this->tenantMessages($workOrder));

        // Day 1.
        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $messages = $this->tenantMessages($workOrder);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Hi Dana, hope you are doing well. Just checking in on the garbage disposal. Did the video help?', $messages[1]->message);
        $this->assertStringContainsString(TenantMessageFormatter::LINK_LEAD, $messages[1]->message);
        $this->assertStringContainsString($token->token, $messages[1]->message);
        $this->assertStringContainsString('(Ref: WO#43900)', $messages[1]->message);
        $this->assertSame('+15125559999', $messages[1]->receiver_number);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($messages[1]->message));
        $this->assertSame(2, $token->fresh()->notified_count);

        // Running again the same day sends nothing more.
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertCount(2, $this->tenantMessages($workOrder));

        // Day 2.
        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $messages = $this->tenantMessages($workOrder);
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('Hi Dana, checking in again on the garbage disposal. Were you able to give the video a try?', $messages[2]->message);

        // Day 3, the last one.
        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $messages = $this->tenantMessages($workOrder);
        $this->assertCount(4, $messages);
        $this->assertStringContainsString('Hi Dana, following up one last time on the garbage disposal.', $messages[3]->message);
        $this->assertSame(TenantEasyFixService::FOLLOW_UP_MAX_NOTIFICATIONS, $token->fresh()->notified_count);

        // Day 4: silence.
        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertCount(4, $this->tenantMessages($workOrder));

        // Three ledger rows under the check-in key, none under the photo reminder key.
        $this->assertSame(3, Activity::query()->inLog(AutomatedMessageLogService::LOG_NAME)->where('event', 'tenant_easy_fix_follow_up_sms')->count());
        $this->assertSame(0, Activity::query()->inLog(AutomatedMessageLogService::LOG_NAME)->where('event', 'tenant_portal_link_reminder_sms')->count());

        Queue::assertPushed(SendConversationMessageJob::class, 4);
    }

    public function test_the_check_ins_stop_once_the_tenant_replies(): void
    {
        [$workOrder] = $this->textedWorkOrder();

        Conversation::create([
            'message' => 'That worked, thank you!',
            'sender_number' => '+15125559999',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
            'is_mms' => false,
        ]);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertCount(2, $this->tenantMessages($workOrder));
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_the_check_ins_stop_once_the_tenant_adds_a_photo(): void
    {
        [$workOrder, $token] = $this->textedWorkOrder();

        $token->update(['completed_at' => now()]);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertCount(1, $this->tenantMessages($workOrder));
    }

    public function test_the_check_ins_stop_once_a_vendor_is_assigned(): void
    {
        [$workOrder] = $this->textedWorkOrder();

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-900',
            'name' => 'Ace Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertCount(1, $this->tenantMessages($workOrder));
    }

    public function test_the_check_ins_stop_once_the_work_order_moves_on(): void
    {
        [$workOrder] = $this->textedWorkOrder();

        $scheduled = ServiceStatus::query()->firstOrCreate(['name' => 'Scheduled'], ['description' => 'Scheduled']);
        $workOrder->update(['service_status_id' => $scheduled->id]);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertCount(1, $this->tenantMessages($workOrder));

        // Closed work orders are left alone too.
        $workOrder->update(['service_status_id' => ServiceStatus::query()->where('name', 'New')->value('id'), 'status' => 'Closed']);
        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertCount(1, $this->tenantMessages($workOrder));
    }

    public function test_the_check_ins_follow_the_easy_fix_gate_not_the_photo_link_gate(): void
    {
        [$workOrder] = $this->textedWorkOrder();

        config([
            'services.twilio.tenant_easy_fix_sms' => false,
            'services.twilio.tenant_portal_sms' => true,
        ]);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        // Neither a check-in nor the generic photo reminder.
        $this->assertCount(1, $this->tenantMessages($workOrder));
    }

    public function test_a_photo_link_the_woc_set_by_hand_still_waits_two_weekdays(): void
    {
        // The generic path: status set in PropertyWare, no how-to text.
        config(['services.twilio.tenant_portal_sms' => true, 'services.twilio.maintenance_number' => '+12813787957']);

        $workOrder = WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43901,
            'service_status_id' => ServiceStatus::query()->where('name', TenantEasyFixService::EASY_FIX_STATUS)->value('id'),
            'description' => 'Light in the hallway is out',
            'tenant_id' => $this->makeTenant()->id,
        ]);
        $token = TenantUploadToken::create([
            'token' => 'demo-tenant-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
            'notified_count' => 1,
            'last_notified_at' => now(),
        ]);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertSame(1, $token->fresh()->notified_count);

        $this->nextWeekday();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertSame(2, $token->fresh()->notified_count);

        $reminder = $this->tenantMessages($workOrder)[0];
        $this->assertStringContainsString('please upload photos', $reminder->message);
        $this->assertStringNotContainsString('Did the video help', $reminder->message);
    }
}
